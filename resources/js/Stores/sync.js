const DB_NAME = 'timeclock-offline'
const DB_VERSION = 1
const STORE_NAME = 'attendanceQueue'

/** @type {Promise<IDBDatabase> | null} */
let dbPromise = null
/** @type {Promise<void> | null} */
let flushPromise = null
let initialized = false

const openDatabase = () => {
    if (!dbPromise) {
        dbPromise = new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION)

            request.onupgradeneeded = () => {
                const db = request.result

                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    const store = db.createObjectStore(STORE_NAME, {
                        keyPath: 'offlineId',
                    })
                    store.createIndex('status', 'status')
                    store.createIndex('createdAt', 'createdAt')
                }
            }

            request.onsuccess = () => resolve(request.result)
            request.onerror = () => reject(request.error)
        })
    }

    return dbPromise
}

/**
 * @param {IDBTransactionMode} mode
 * @param {(store: IDBObjectStore) => IDBRequest} callback
 */
const withStore = async (mode, callback) => {
    const db = await openDatabase()

    return new Promise((resolve, reject) => {
        const transaction = db.transaction(STORE_NAME, mode)
        const store = transaction.objectStore(STORE_NAME)
        const request = callback(store)

        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
    })
}

/** @param {any} record */
const putRecord = (record) =>
    withStore('readwrite', (store) => store.put(record))
/** @param {IDBValidKey} offlineId */
const deleteRecord = (offlineId) =>
    withStore('readwrite', (store) => store.delete(offlineId))
const getAllRecords = () => withStore('readonly', (store) => store.getAll())

const csrfToken = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || ''

/**
 * @typedef {Object} AttendanceRecord
 * @property {string} offlineId
 * @property {string} occurredAt
 * @property {string} employeeIdentifier
 * @property {string} attendanceMethod
 * @property {string} attendanceType
 * @property {number} latitude
 * @property {number} longitude
 * @property {string} [location]
 * @property {Blob} imageBlob
 * @property {string} imageFileName
 * @property {string} [status]
 * @property {number} [attempts]
 * @property {string|null} [lastError]
 * @property {number} [createdAt]
 */

/**
 * @param {number} latitude
 * @param {number} longitude
 * @returns {Promise<string>}
 */
const resolveAddress = async (latitude, longitude) => {
    if (!navigator.onLine) return ''

    const controller = new AbortController()
    const timeout = setTimeout(() => controller.abort(), 2500)

    try {
        const params = new URLSearchParams({
            format: 'jsonv2',
            lat: String(latitude),
            lon: String(longitude),
        })
        const response = await fetch(
            `https://nominatim.openstreetmap.org/reverse?${params.toString()}`,
            {
                headers: {
                    Accept: 'application/json',
                },
                signal: controller.signal,
            },
        )

        if (!response.ok) return ''

        const payload = await response.json()

        return payload.display_name || ''
    } catch {
        return ''
    } finally {
        clearTimeout(timeout)
    }
}

/**
 * @param {AttendanceRecord} record
 * @returns {FormData}
 */
const buildAttendanceFormData = (record) => {
    const formData = new FormData()

    formData.append('offline_id', record.offlineId)
    formData.append('occurred_at', record.occurredAt)
    formData.append('rfid', record.employeeIdentifier)
    formData.append('attendance_method', record.attendanceMethod)
    formData.append('attendance_type', record.attendanceType)
    formData.append('latitude', String(record.latitude))
    formData.append('longitude', String(record.longitude))
    formData.append('location', record.location || '')
    formData.append('attendance-image', record.imageBlob, record.imageFileName)

    const token = csrfToken()
    if (token) formData.append('_token', token)

    return formData
}

/**
 * @param {AttendanceRecord} record
 * @returns {Promise<AttendanceRecord>}
 */
const enrichRecordLocation = async (record) => {
    if (record.location) return record

    const resolvedLocation = await resolveAddress(
        record.latitude,
        record.longitude,
    )

    return resolvedLocation ? { ...record, location: resolvedLocation } : record
}

/**
 * @param {AttendanceRecord} record
 * @returns {Promise<{payload: any, record: AttendanceRecord}>}
 */
const postAttendanceRecord = async (record) => {
    const enrichedRecord = await enrichRecordLocation(record)
    const response = await fetch('/attendance/record-time-in', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: buildAttendanceFormData(enrichedRecord),
    })

    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
        const error = /** @type {Error & {payload:any, status:number}} */ (
            new Error(
                payload.message ||
                    Object.values(payload.errors ?? {})?.[0]?.[0] ||
                    'Failed to sync attendance.',
            )
        )
        error.payload = payload
        error.status = response.status
        throw error
    }

    return {
        payload,
        record: enrichedRecord,
    }
}

const sortOldestFirst = (/** @type {any[]} */ records) =>
    [...records].sort((a, b) => a.createdAt - b.createdAt)

const syncApi = {
    isOnline: navigator.onLine,
    isFlushing: false,

    initialize() {
        if (initialized) return

        const updateNetworkState = () => {
            this.isOnline = navigator.onLine

            if (this.isOnline) {
                this.flushQueue().catch((error) => {
                    console.error(
                        'Unable to flush offline attendance queue:',
                        error,
                    )
                })
            }
        }

        window.addEventListener('online', updateNetworkState)
        window.addEventListener('offline', updateNetworkState)
        initialized = true

        if (this.isOnline) {
            this.flushQueue().catch((error) => {
                console.error(
                    'Unable to flush offline attendance queue:',
                    error,
                )
            })
        }
    },

    /** @param {AttendanceRecord} data */
    async enqueueAttendance(data) {
        const record = {
            status: 'pending',
            attempts: 0,
            lastError: null,
            createdAt: Date.now(),
            ...data,
        }

        await putRecord(record)

        return record
    },

    /** @param {AttendanceRecord} data */
    async submitOrQueueAttendance(data) {
        if (!navigator.onLine) {
            await this.enqueueAttendance(data)

            return {
                queued: true,
                message:
                    'Attendance saved offline. It will sync when the connection returns.',
            }
        }

        try {
            const { payload } = await postAttendanceRecord(data)

            return {
                queued: false,
                payload,
            }
        } catch (error) {
            if (
                error &&
                typeof error === 'object' &&
                'status' in error &&
                typeof error.status === 'number' &&
                error.status < 500
            ) {
                throw error
            }

            await this.enqueueAttendance({
                ...data,
                attempts: 1,
                lastError:
                    error instanceof Error ? error.message : 'Sync failed.',
            })

            return {
                queued: true,
                message:
                    'Connection failed. Attendance saved offline and will retry automatically.',
            }
        }
    },

    async flushQueue() {
        if (!navigator.onLine) return
        if (flushPromise) return flushPromise

        this.isFlushing = true
        flushPromise = (async () => {
            const pendingRecords = sortOldestFirst(await getAllRecords())

            for (const record of pendingRecords) {
                try {
                    await postAttendanceRecord(record)
                    await deleteRecord(record.offlineId)
                } catch (error) {
                    await putRecord({
                        ...record,
                        attempts: (record.attempts ?? 0) + 1,
                        lastError:
                            error instanceof Error
                                ? error.message
                                : 'Sync failed.',
                    })

                    // Preserve event order. If an older time-in cannot sync,
                    // do not attempt a newer time-out yet.
                    break
                }
            }
        })().finally(() => {
            this.isFlushing = false
            flushPromise = null
        })

        return flushPromise
    },
}

export const useSyncStore = () => {
    syncApi.initialize()

    return syncApi
}

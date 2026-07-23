const DB_NAME = 'timeclock-offline'
const DB_VERSION = 2
const ATTENDANCE_STORE = 'attendanceQueue'
const AUTH_MANIFEST_STORE = 'authManifest'
const EMPLOYEES_STORE = 'employees'
const RFID_INDEX_STORE = 'rfidIndex'
const PIN_INDEX_STORE = 'pinIndex'
const FACE_EMBEDDINGS_STORE = 'faceEmbeddings'
const SYNC_LOG_STORE = 'syncLog'

/** @type {Promise<IDBDatabase> | null} */
let dbPromise = null
/** @type {Promise<void> | null} */
let flushPromise = null
let initialized = false
let kioskApiToken = ''

const openDatabase = () => {
    if (!dbPromise) {
        dbPromise = new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION)

            request.onupgradeneeded = () => {
                const db = request.result

                if (!db.objectStoreNames.contains(ATTENDANCE_STORE)) {
                    const store = db.createObjectStore(ATTENDANCE_STORE, {
                        keyPath: 'offlineId',
                    })
                    store.createIndex('status', 'status')
                    store.createIndex('createdAt', 'createdAt')
                }

                if (!db.objectStoreNames.contains(AUTH_MANIFEST_STORE)) {
                    db.createObjectStore(AUTH_MANIFEST_STORE, { keyPath: 'key' })
                }

                if (!db.objectStoreNames.contains(EMPLOYEES_STORE)) {
                    const store = db.createObjectStore(EMPLOYEES_STORE, {
                        keyPath: 'employee_number',
                    })
                    store.createIndex('employee_id', 'employee_id')
                    store.createIndex('auth_revision', 'auth_revision')
                }

                if (!db.objectStoreNames.contains(RFID_INDEX_STORE)) {
                    db.createObjectStore(RFID_INDEX_STORE, { keyPath: 'hash' })
                }

                if (!db.objectStoreNames.contains(PIN_INDEX_STORE)) {
                    db.createObjectStore(PIN_INDEX_STORE, { keyPath: 'verifier' })
                }

                if (!db.objectStoreNames.contains(FACE_EMBEDDINGS_STORE)) {
                    const store = db.createObjectStore(FACE_EMBEDDINGS_STORE, {
                        keyPath: 'id',
                    })
                    store.createIndex('employee_number', 'employee_number')
                }

                if (!db.objectStoreNames.contains(SYNC_LOG_STORE)) {
                    db.createObjectStore(SYNC_LOG_STORE, {
                        keyPath: 'id',
                        autoIncrement: true,
                    })
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
        const transaction = db.transaction(ATTENDANCE_STORE, mode)
        const store = transaction.objectStore(ATTENDANCE_STORE)
        const request = callback(store)

        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
    })
}

const withNamedStore = async (storeName, mode, callback) => {
    const db = await openDatabase()

    return new Promise((resolve, reject) => {
        const transaction = db.transaction(storeName, mode)
        const store = transaction.objectStore(storeName)
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

const getStoreRecord = (storeName, key) =>
    withNamedStore(storeName, 'readonly', (store) => store.get(key))
const putStoreRecord = (storeName, record) =>
    withNamedStore(storeName, 'readwrite', (store) => store.put(record))
const deleteStoreRecord = (storeName, key) =>
    withNamedStore(storeName, 'readwrite', (store) => store.delete(key))

const csrfToken = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || ''

const csrfHeaders = () => {
    const token = csrfToken()

    return token ? { 'X-CSRF-TOKEN': token } : {}
}

const kioskApiHeaders = () =>
    kioskApiToken
        ? {
              'X-Kiosk-Api-Token': kioskApiToken,
          }
        : {}

/**
 * @typedef {Object} AttendanceRecord
 * @property {string} offlineId
 * @property {string} occurredAt
 * @property {string} employeeIdentifier
 * @property {string} [employeeName]
 * @property {string} [employeeBranch]
 * @property {string} attendanceMethod
 * @property {string} [attendanceType]
 * @property {number} [authCacheRevision]
 * @property {string} [cacheStateAtRecordTime]
 * @property {number} [matchedAuthRevision]
 * @property {any} [authMetadata]
 * @property {number} latitude
 * @property {number} longitude
 * @property {string} [location]
 * @property {Blob} [imageBlob]
 * @property {string} [imageFileName]
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
    if (record.attendanceType) {
        formData.append('attendance_type', record.attendanceType)
    }
    if (record.authCacheRevision) {
        formData.append('auth_cache_revision', String(record.authCacheRevision))
    }
    if (record.cacheStateAtRecordTime) {
        formData.append('cache_state_at_record_time', record.cacheStateAtRecordTime)
    }
    if (record.matchedAuthRevision) {
        formData.append('matched_auth_revision', String(record.matchedAuthRevision))
    }
    if (record.authMetadata) {
        formData.append('auth_metadata', JSON.stringify(record.authMetadata))
    }
    formData.append('latitude', String(record.latitude))
    formData.append('longitude', String(record.longitude))
    formData.append('location', record.location || '')
    if (record.locationSource) {
        formData.append('location_source', record.locationSource)
    }
    if (record.imageBlob && record.imageFileName) {
        formData.append(
            'attendance-image',
            record.imageBlob,
            record.imageFileName,
        )
    }

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
const postAttendanceRecord = async (record, options = {}) => {
    const dispatchRecordedEvent = options.dispatchRecordedEvent ?? true
    const enrichedRecord = await enrichRecordLocation(record)
    const response = await fetch('/attendance/record-time-in', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
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

    const result = {
        payload,
        record: enrichedRecord,
    }

    if (dispatchRecordedEvent) {
        window.dispatchEvent(
            new CustomEvent('attendance:recorded', {
                detail: result,
            }),
        )
    }

    return result
}

const blobToDataUrl = (blob) =>
    new Promise((resolve, reject) => {
        const reader = new FileReader()

        reader.onload = () => resolve(reader.result)
        reader.onerror = () => reject(reader.error)
        reader.readAsDataURL(blob)
    })

const buildKioskAttendanceRecord = async (record) => {
    const enrichedRecord = await enrichRecordLocation(record)

    return {
        offline_uuid: enrichedRecord.offlineId,
        employee_id: enrichedRecord.employeeIdentifier,
        auth_method: enrichedRecord.attendanceMethod,
        kiosk_id: enrichedRecord.authMetadata?.kiosk_id ?? undefined,
        local_recorded_at: enrichedRecord.occurredAt,
        auth_cache_revision: enrichedRecord.authCacheRevision,
        cache_state_at_record_time: enrichedRecord.cacheStateAtRecordTime,
        matched_auth_revision: enrichedRecord.matchedAuthRevision,
        attendance_type: enrichedRecord.attendanceType,
        latitude: enrichedRecord.latitude,
        longitude: enrichedRecord.longitude,
        location: enrichedRecord.location || '',
        location_source: enrichedRecord.locationSource || undefined,
        attendance_image: enrichedRecord.imageBlob
            ? await blobToDataUrl(enrichedRecord.imageBlob)
            : undefined,
        metadata: enrichedRecord.authMetadata ?? {},
    }
}

const postAttendanceBatch = async (records) => {
    const payloadRecords = []

    for (const record of records) {
        payloadRecords.push(await buildKioskAttendanceRecord(record))
    }

    const response = await fetch('/api/kiosk/attendance/sync', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...kioskApiHeaders(),
            ...csrfHeaders(),
        },
        body: JSON.stringify({ records: payloadRecords }),
    })

    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
        const error = /** @type {Error & {payload:any, status:number}} */ (
            new Error(
                payload.message ||
                    Object.values(payload.errors ?? {})?.[0]?.[0] ||
                    'Failed to sync attendance queue.',
            )
        )
        error.payload = payload
        error.status = response.status
        throw error
    }

    return payload
}

const sortOldestFirst = (/** @type {any[]} */ records) =>
    [...records].sort((a, b) => a.createdAt - b.createdAt)

const pendingAttendanceRecords = (records) =>
    sortOldestFirst(records).filter((record) => record.status === 'pending')

const terminalSyncStatuses = ['accepted', 'accepted_with_warning', 'rejected', 'needs_review']

const normalizeCredential = (value) =>
    String(value ?? '').replace(/[\u0000-\u001F\u007F]/g, '').trim()

const sha256Hex = async (value) => {
    const bytes = new TextEncoder().encode(value)
    const digest = await crypto.subtle.digest('SHA-256', bytes)

    return [...new Uint8Array(digest)]
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('')
}

const hashCredential = async (value, manifest) =>
    sha256Hex(`${normalizeCredential(value)}|${manifest?.hash?.salt ?? ''}`)

const getAuthManifest = async () =>
    (await getStoreRecord(AUTH_MANIFEST_STORE, 'current')) ?? null

const cacheStateFromManifest = (manifest) => {
    if (!manifest?.generated_at) return 'expired'

    const generatedAt = Date.parse(manifest.generated_at)
    if (!Number.isFinite(generatedAt)) return 'expired'

    const ageSeconds = Math.max(0, Math.floor((Date.now() - generatedAt) / 1000))
    const freshSeconds = Number(manifest.cache_policy?.fresh_seconds ?? 86400)
    const staleSeconds = Number(manifest.cache_policy?.stale_seconds ?? 604800)

    if (ageSeconds <= freshSeconds) return 'fresh'
    if (ageSeconds <= staleSeconds) return 'stale'

    return 'expired'
}

const applyKioskAuthPayload = async (payload) => {
    if (!payload?.manifest) return null

    await putStoreRecord(AUTH_MANIFEST_STORE, {
        key: 'current',
        ...payload.manifest,
        synced_at: new Date().toISOString(),
    })

    for (const employee of payload.records ?? []) {
        const employeeNumber = employee.employee_number
        if (!employeeNumber) continue

        const previous = await getStoreRecord(EMPLOYEES_STORE, employeeNumber)
        const previousRevision = Number(previous?.auth_revision ?? 0)
        const nextRevision = Number(employee.auth_revision ?? 0)

        if (previous && previousRevision > nextRevision) {
            continue
        }

        for (const hash of previous?.rfid_hashes ?? []) {
            await deleteStoreRecord(RFID_INDEX_STORE, hash)
        }
        if (previous?.keypad_pin_hash) {
            await deleteStoreRecord(PIN_INDEX_STORE, previous.keypad_pin_hash)
        }

        await putStoreRecord(EMPLOYEES_STORE, employee)

        for (const hash of employee.rfid_hashes ?? []) {
            await putStoreRecord(RFID_INDEX_STORE, {
                hash,
                employee_number: employeeNumber,
                auth_revision: employee.auth_revision,
            })
        }

        if (employee.keypad_pin_hash) {
            await putStoreRecord(PIN_INDEX_STORE, {
                verifier: employee.keypad_pin_hash,
                employee_number: employeeNumber,
                auth_revision: employee.auth_revision,
            })
        }

        for (const embedding of employee.face_embeddings ?? []) {
            await putStoreRecord(FACE_EMBEDDINGS_STORE, {
                ...embedding,
                employee_number: employeeNumber,
            })
        }
    }

    for (const tombstone of payload.tombstones ?? []) {
        const employeeNumber = tombstone.employee_number
        if (!employeeNumber) continue

        const previous = await getStoreRecord(EMPLOYEES_STORE, employeeNumber)
        for (const hash of previous?.rfid_hashes ?? []) {
            await deleteStoreRecord(RFID_INDEX_STORE, hash)
        }
        if (previous?.keypad_pin_hash) {
            await deleteStoreRecord(PIN_INDEX_STORE, previous.keypad_pin_hash)
        }
        await putStoreRecord(EMPLOYEES_STORE, {
            ...(previous ?? {}),
            ...tombstone,
            active: false,
            revoked: true,
        })
    }

    await putStoreRecord(SYNC_LOG_STORE, {
        type: 'auth-sync',
        revision: payload.manifest.current_revision,
        created_at: new Date().toISOString(),
    })

    return payload.manifest
}

const fetchJson = async (url) => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...kioskApiHeaders(),
        },
    })

    if (!response.ok) {
        throw new Error('Unable to sync kiosk auth cache.')
    }

    return response.json()
}

const syncKioskAuthCache = async () => {
    if (!navigator.onLine) return getAuthManifest()

    const localManifest = await getAuthManifest()
    const remoteManifest = await fetchJson('/api/kiosk/auth/manifest')
    const localRevision = Number(localManifest?.current_revision ?? 0)
    const remoteRevision = Number(remoteManifest?.current_revision ?? 0)

    if (localRevision >= remoteRevision) {
        return localManifest
    }

    const endpoint =
        localRevision > 0
            ? `/api/kiosk/auth/sync?since_revision=${encodeURIComponent(String(localRevision))}`
            : '/api/kiosk/auth/full'

    return applyKioskAuthPayload(await fetchJson(endpoint))
}

const employeeFromIndex = async (indexEntry, method, manifest) => {
    if (!indexEntry?.employee_number) return null

    const employee = await getStoreRecord(EMPLOYEES_STORE, indexEntry.employee_number)
    if (!employee || employee.revoked || employee.active === false) return null

    return {
        employee,
        decision: {
            authCacheRevision: Number(manifest?.current_revision ?? 0) || undefined,
            cacheStateAtRecordTime: cacheStateFromManifest(manifest),
            matchedAuthRevision: Number(indexEntry.auth_revision ?? employee.auth_revision ?? 0) || undefined,
            authMetadata: {
                method,
                source: 'indexeddb-auth-cache',
                face_embedding_revision: employee.face_embedding_revision,
                face_model_version: employee.face_model_version,
            },
        },
    }
}

const syncApi = {
    isOnline: navigator.onLine,
    isFlushing: false,

    setKioskApiToken(token) {
        kioskApiToken = String(token ?? '')
    },

    initialize() {
        if (initialized) return

        const updateNetworkState = () => {
            this.isOnline = navigator.onLine

            if (this.isOnline) {
                this.syncKioskAuthCache().catch((error) => {
                    console.error('Unable to sync kiosk auth cache:', error)
                })
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
            this.syncKioskAuthCache().catch((error) => {
                console.error('Unable to sync kiosk auth cache:', error)
            })
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
            const { payload, record } = await postAttendanceRecord(data, {
                dispatchRecordedEvent: false,
            })

            return {
                queued: false,
                payload,
                record,
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
            const pendingRecords = pendingAttendanceRecords(await getAllRecords())

            if (pendingRecords.length === 0) return

            try {
                const payload = await postAttendanceBatch(pendingRecords)
                const resultsByOfflineId = new Map(
                    (payload.results ?? []).map((result) => [
                        result.offline_uuid,
                        result,
                    ]),
                )

                for (const record of pendingRecords) {
                    const result = resultsByOfflineId.get(record.offlineId)

                    if (!result || !terminalSyncStatuses.includes(result.status)) {
                        await putRecord({
                            ...record,
                            attempts: (record.attempts ?? 0) + 1,
                            lastError:
                                result?.message ??
                                'Attendance sync did not return a terminal result.',
                        })
                        break
                    }

                    if (
                        result.status === 'accepted' ||
                        result.status === 'accepted_with_warning'
                    ) {
                        await deleteRecord(record.offlineId)
                        window.dispatchEvent(
                            new CustomEvent('attendance:recorded', {
                                detail: {
                                    payload: {
                                        message: result.message,
                                        attendance_id: result.attendance_id,
                                        sync_status: result.status,
                                        employee_id: record.employeeIdentifier,
                                        employee_branch: record.employeeBranch,
                                    },
                                    record,
                                },
                            }),
                        )
                        continue
                    }

                    await putRecord({
                        ...record,
                        status: result.status,
                        attempts: (record.attempts ?? 0) + 1,
                        lastError: result.message || 'Attendance needs review.',
                        syncedAt: new Date().toISOString(),
                    })
                }
            } catch (error) {
                const firstRecord = pendingRecords[0]
                await putRecord({
                    ...firstRecord,
                    attempts: (firstRecord.attempts ?? 0) + 1,
                    lastError:
                        error instanceof Error ? error.message : 'Sync failed.',
                })
            }
        })().finally(() => {
            this.isFlushing = false
            flushPromise = null
        })

        return flushPromise
    },

    async getQueuedAttendances() {
        return sortOldestFirst(await getAllRecords())
    },

    async deleteQueuedAttendance(offlineId) {
        await deleteRecord(offlineId)
    },

    async syncKioskAuthCache() {
        return syncKioskAuthCache()
    },

    async getAuthManifest() {
        return getAuthManifest()
    },

    cacheState(manifest) {
        return cacheStateFromManifest(manifest)
    },

    async verifyLocalCredential(method, credential) {
        const localManifest = await getAuthManifest()
        const manifest = navigator.onLine
            ? await syncKioskAuthCache().catch(() => localManifest)
            : localManifest

        if (!manifest?.hash?.salt) return null

        const verifier = await hashCredential(credential, manifest)
        const storeName = method === 'keypad' ? PIN_INDEX_STORE : RFID_INDEX_STORE
        const key = method === 'keypad' ? verifier : verifier
        const indexEntry = await getStoreRecord(storeName, key)

        return employeeFromIndex(indexEntry, method, manifest)
    },

    async getCachedEmployeeByNumber(employeeNumber, method = 'face') {
        const localManifest = await getAuthManifest()
        const manifest = navigator.onLine
            ? await syncKioskAuthCache().catch(() => localManifest)
            : localManifest
        const employee = await getStoreRecord(
            EMPLOYEES_STORE,
            normalizeCredential(employeeNumber),
        )

        if (!employee || employee.revoked || employee.active === false) return null

        return {
            employee,
            decision: {
                authCacheRevision: Number(manifest?.current_revision ?? 0) || undefined,
                cacheStateAtRecordTime: cacheStateFromManifest(manifest),
                matchedAuthRevision: Number(employee.auth_revision ?? 0) || undefined,
                authMetadata: {
                    method,
                    source: 'indexeddb-auth-cache',
                    face_embedding_revision: employee.face_embedding_revision,
                    face_model_version: employee.face_model_version,
                },
            },
        }
    },
}

export const useSyncStore = (token = null) => {
    if (token !== null) {
        syncApi.setKioskApiToken(token)
    }

    syncApi.initialize()

    return syncApi
}

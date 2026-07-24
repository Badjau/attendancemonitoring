import { ref } from 'vue'

const LAST_KNOWN_COORDS_KEY = 'timeclock:lastKnownCoords'

export function useGeolocator() {
    const coords = ref(
        /** @type {{latitude:number, longitude:number, accuracy:number, address?:string}} */ ({}),
    )
    const error = ref('')
    const loading = ref(false)
    const accuracyWarning = ref(false)
    const address = ref('')
    const usingCachedLocation = ref(false)
    const locationSource = ref('')
    let pendingLocationRequest = null
    let locationWatchId = null

    const readLastKnownCoords = () => {
        try {
            const cached = JSON.parse(
                localStorage.getItem(LAST_KNOWN_COORDS_KEY) || 'null',
            )

            if (
                !cached ||
                !Number.isFinite(cached.latitude) ||
                !Number.isFinite(cached.longitude)
            ) {
                return null
            }

            return cached
        } catch {
            return null
        }
    }

    /**
     * @param {{latitude:number,longitude:number,accuracy:number,address?:string}} location
     */
    const saveLastKnownCoords = (location) => {
        localStorage.setItem(
            LAST_KNOWN_COORDS_KEY,
            JSON.stringify({
                latitude: location.latitude,
                longitude: location.longitude,
                accuracy: location.accuracy,
                address: location.address || '',
                capturedAt: new Date().toISOString(),
            }),
        )
    }

    /**
     * @param {number} latitude
     * @param {number} longitude
     * @returns {Promise<string>}
     */
    const resolveAddress = async (latitude, longitude) => {
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

    const applyPosition = (position, shouldResolveAddress = true) => {
        const latitude = position.coords.latitude
        const longitude = position.coords.longitude

        coords.value = {
            latitude,
            longitude,
            accuracy: position.coords.accuracy,
            address: coords.value.address || '',
        }
        usingCachedLocation.value = false
        locationSource.value = 'live'
        accuracyWarning.value = position.coords.accuracy > 150 //Lower is better
        error.value = ''
        loading.value = false
        saveLastKnownCoords(coords.value)

        // GPS coordinates are the required attendance proof.
        // Address lookup is only a convenience, so do it later and
        // only when a network connection is available.
        if (shouldResolveAddress && navigator.onLine) {
            resolveAddress(latitude, longitude).then((resolvedAddress) => {
                if (!resolvedAddress) return

                coords.value = {
                    ...coords.value,
                    address: resolvedAddress,
                }
                address.value = resolvedAddress
                saveLastKnownCoords(coords.value)
            })
        }
    }

    const getLocation = () => {
        if (pendingLocationRequest) return pendingLocationRequest

        error.value = ''

        if (!navigator.geolocation) {
            error.value = 'Location access denied. Please enable GPS.'
            return Promise.reject(new Error(error.value))
        }

        loading.value = true

        pendingLocationRequest = new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    applyPosition(position)
                    resolve(coords.value)
                },
                () => {
                    const cachedLocation = readLastKnownCoords()

                    if (!navigator.onLine && cachedLocation) {
                        coords.value = cachedLocation
                        address.value = cachedLocation.address || ''
                        usingCachedLocation.value = true
                        locationSource.value = 'cached'
                        accuracyWarning.value = false
                        error.value = ''
                        loading.value = false
                        resolve(coords.value)
                        return
                    }

                    error.value = 'Location access denied. Please enable GPS.'
                    loading.value = false
                    reject(new Error(error.value))
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 5000,
                },
            )
        }).finally(() => {
            pendingLocationRequest = null
        })

        return pendingLocationRequest
    }

    const startLocationWatch = () => {
        if (locationWatchId !== null) return

        error.value = ''

        if (!navigator.geolocation) {
            error.value = 'Location access denied. Please enable GPS.'
            return
        }

        loading.value = true

        locationWatchId = navigator.geolocation.watchPosition(
            (position) => applyPosition(position, false),
            () => {
                const cachedLocation = readLastKnownCoords()

                if (!navigator.onLine && cachedLocation) {
                    coords.value = cachedLocation
                    address.value = cachedLocation.address || ''
                    usingCachedLocation.value = true
                    locationSource.value = 'cached'
                    accuracyWarning.value = false
                    error.value = ''
                    loading.value = false
                    return
                }

                error.value = 'Location access denied. Please enable GPS.'
                loading.value = false
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0,
            },
        )
    }

    const stopLocationWatch = () => {
        if (locationWatchId === null || !navigator.geolocation) return

        navigator.geolocation.clearWatch(locationWatchId)
        locationWatchId = null
    }

    const resetLocation = () => {
        stopLocationWatch()
        coords.value = {
            latitude: 0,
            longitude: 0,
            accuracy: 0,
        }
        error.value = ''
        loading.value = false
        accuracyWarning.value = false
        address.value = ''
        usingCachedLocation.value = false
        locationSource.value = ''
    }

    return {
        coords,
        error,
        loading,
        accuracyWarning,
        address,
        usingCachedLocation,
        locationSource,
        getLocation,
        startLocationWatch,
        stopLocationWatch,
        resetLocation,
    }
}

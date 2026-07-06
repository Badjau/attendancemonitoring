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

    const getLocation = () => {
        error.value = ''

        if (!navigator.geolocation) {
            error.value = 'Location access denied. Please enable GPS.'
            return Promise.reject(new Error(error.value))
        }

        loading.value = true

        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const latitude = position.coords.latitude
                    const longitude = position.coords.longitude

                    coords.value = {
                        latitude,
                        longitude,
                        accuracy: position.coords.accuracy,
                        address: '',
                    }
                    usingCachedLocation.value = false
                    locationSource.value = 'live'
                    accuracyWarning.value = position.coords.accuracy > 150 //Lower is better
                    loading.value = false
                    saveLastKnownCoords(coords.value)
                    resolve(coords.value)

                    // GPS coordinates are the required attendance proof.
                    // Address lookup is only a convenience, so do it later and
                    // only when a network connection is available.
                    if (navigator.onLine) {
                        resolveAddress(latitude, longitude).then(
                            (resolvedAddress) => {
                                if (!resolvedAddress) return

                                coords.value = {
                                    ...coords.value,
                                    address: resolvedAddress,
                                }
                                address.value = resolvedAddress
                            },
                        )
                    }
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
                    maximumAge: 0,
                },
            )
        })
    }

    const resetLocation = () => {
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
        resetLocation,
    }
}

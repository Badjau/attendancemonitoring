import {ref} from 'vue';

export function useGeolocator() {
    const coords = ref({});
    const error = ref('');
    const loading = ref(false);
    const accuracyWarning = ref(false);
    const address = ref('');

    const resolveAddress = async (latitude, longitude) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 2500);

        try {
            const params = new URLSearchParams({
                format: 'jsonv2',
                lat: String(latitude),
                lon: String(longitude),
            });
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                },
                signal: controller.signal,
            });

            if (!response.ok) return '';

            const payload = await response.json();
            return payload.display_name || '';
        } catch {
            return '';
        } finally {
            clearTimeout(timeout);
        }
    };

    const getLocation = () => {
        error.value = '';

        if (!navigator.geolocation) {
            error.value = 'Location access denied. Please enable GPS.';
            return Promise.reject(new Error(error.value));
        }

        loading.value = true;

        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    const latitude = position.coords.latitude;
                    const longitude = position.coords.longitude;
                    const resolvedAddress = await resolveAddress(latitude, longitude);

                    coords.value = {
                        latitude,
                        longitude,
                        accuracy: position.coords.accuracy,
                        address: resolvedAddress,
                    };
                    address.value = resolvedAddress;
                    accuracyWarning.value = position.coords.accuracy > 100;
                    loading.value = false;
                    resolve(coords.value);
                },
                () => {
                    error.value = 'Location access denied. Please enable GPS.';
                    loading.value = false;
                    reject(new Error(error.value));
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0,
                },
            );
        });
    };

    const resetLocation = () => {
        coords.value = {};
        error.value = '';
        loading.value = false;
        accuracyWarning.value = false;
        address.value = '';
    };

    return {
        coords,
        error,
        loading,
        accuracyWarning,
        address,
        getLocation,
        resetLocation,
    };
}

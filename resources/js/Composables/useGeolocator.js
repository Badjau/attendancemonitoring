import {ref} from 'vue';

export function useGeolocator() {
    const coords = ref({});
    const error = ref('');
    const loading = ref(false);
    const accuracyWarning = ref(false);

    const getLocation = () => {
        error.value = '';

        if (!navigator.geolocation) {
            error.value = 'Location access denied. Please enable GPS.';
            return Promise.reject(new Error(error.value));
        }

        loading.value = true;

        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    coords.value = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                    };
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
    };

    return {
        coords,
        error,
        loading,
        accuracyWarning,
        getLocation,
        resetLocation,
    };
}

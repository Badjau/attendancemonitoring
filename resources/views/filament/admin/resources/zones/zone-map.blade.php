@php
    $statePath = $getStatePath();
    $latitudeStatePath = str($statePath)->beforeLast('.')->append('.latitude')->toString();
    $longitudeStatePath = str($statePath)->beforeLast('.')->append('.longitude')->toString();
    $radiusStatePath = str($statePath)->beforeLast('.')->append('.radius_meters')->toString();
@endphp

<div
    wire:ignore
    x-data="{
        map: null,
        marker: null,
        circle: null,
        search: '',
        results: [],
        isSearching: false,
        searchError: '',
        mapError: '',
        latitudeStatePath: @js($latitudeStatePath),
        longitudeStatePath: @js($longitudeStatePath),
        radiusStatePath: @js($radiusStatePath),
        latitude: $wire.$entangle(@js($latitudeStatePath), true),
        longitude: $wire.$entangle(@js($longitudeStatePath), true),
        radius: $wire.$entangle(@js($radiusStatePath), true),
        loadLeaflet() {
            if (window.L) return Promise.resolve();

            if (! document.querySelector('link[data-leaflet-css]')) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                link.dataset.leafletCss = 'true';
                document.head.appendChild(link);
            }

            return new Promise((resolve, reject) => {
                const sources = [
                    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
                    'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
                ];
                const existingScript = document.querySelector('script[data-leaflet-script]');

                if (existingScript) {
                    if (window.L) {
                        resolve();
                        return;
                    }

                    existingScript.addEventListener('load', () => resolve(), { once: true });
                    existingScript.addEventListener('error', reject, { once: true });
                    return;
                }

                const loadSource = (index) => {
                    if (! sources[index]) {
                        reject(new Error('Leaflet could not be loaded.'));
                        return;
                    }

                    const script = document.createElement('script');
                    script.src = sources[index];
                    script.dataset.leafletScript = 'true';
                    script.addEventListener('load', () => resolve(), { once: true });
                    script.addEventListener('error', () => {
                        script.remove();
                        loadSource(index + 1);
                    }, { once: true });
                    document.head.appendChild(script);
                };

                loadSource(0);
            });
        },
        initMap() {
            if (! this.$refs.map) return;

            const lat = Number(this.latitude || 14.6309303);
            const lon = Number(this.longitude || 120.9972863);
            const radius = Number(this.radius || 100);

            this.latitude = lat;
            this.longitude = lon;
            this.radius = radius;

            this.map = L.map(this.$refs.map).setView([lat, lon], 16);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(this.map);

            this.marker = L.marker([lat, lon]).addTo(this.map);
            this.circle = L.circle([lat, lon], { radius }).addTo(this.map);

            this.map.on('click', (event) => {
                this.latitude = Number(event.latlng.lat.toFixed(7));
                this.longitude = Number(event.latlng.lng.toFixed(7));
                $wire.set(this.latitudeStatePath, this.latitude, true);
                $wire.set(this.longitudeStatePath, this.longitude, true);
                this.syncMap();
            });

            setTimeout(() => this.map.invalidateSize(), 150);
            setTimeout(() => this.map.invalidateSize(), 500);
        },
        syncMap() {
            if (! this.map || ! this.marker || ! this.circle) return;

            const lat = Number(this.latitude || 14.6309303);
            const lon = Number(this.longitude || 120.9972863);
            const radius = Number(this.radius || 100);
            const latLng = [lat, lon];

            this.marker.setLatLng(latLng);
            this.circle.setLatLng(latLng);
            this.circle.setRadius(radius);
            this.map.setView(latLng);
        },
        setLocation(lat, lon) {
            this.latitude = Number(Number(lat).toFixed(7));
            this.longitude = Number(Number(lon).toFixed(7));
            $wire.set(this.latitudeStatePath, this.latitude, true);
            $wire.set(this.longitudeStatePath, this.longitude, true);
            this.syncMap();
            this.results = [];
        },
        searchLocations() {
            const query = this.search.trim();

            if (query.length < 3) {
                this.results = [];
                this.searchError = 'Enter at least 3 characters.';
                return;
            }

            this.isSearching = true;
            this.searchError = '';

            fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&q=${encodeURIComponent(query)}`, {
                headers: {
                    Accept: 'application/json',
                },
            })
                .then((response) => {
                    if (! response.ok) {
                        throw new Error('Location search failed.');
                    }

                    return response.json();
                })
                .then((data) => {
                    this.results = data;
                    this.searchError = data.length ? '' : 'No locations found.';
                })
                .catch((error) => {
                    console.error(error);
                    this.searchError = 'Unable to search locations right now.';
                })
                .finally(() => {
                    this.isSearching = false;
                });
        },
        init() {
            this.loadLeaflet()
                .then(() => this.initMap())
                .catch((error) => {
                    console.error('Unable to load Leaflet.', error);
                    this.mapError = 'Unable to load the map. Check your internet connection or allow the Leaflet CDN.';
                });

            this.$watch('latitude', () => this.syncMap());
            this.$watch('longitude', () => this.syncMap());
            this.$watch('radius', () => this.syncMap());
        },
    }"
    class="space-y-2"
>
    <div class="text-sm font-medium text-gray-700 dark:text-gray-200">
        Map
    </div>

    <div class="relative">
        <div
            class="absolute left-3 right-3 top-3 rounded-xl bg-white p-3 shadow-lg dark:bg-gray-900"
            style="z-index: 1000;"
        >
            <div class="flex gap-2">
                <input
                    x-model="search"
                    type="search"
                    autocomplete="off"
                    placeholder="Search location"
                    class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-950 shadow-sm outline-none transition focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    x-on:keydown.enter.prevent="searchLocations()"
                />

                <button
                    type="button"
                    x-on:click="searchLocations()"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-60"
                    x-bind:disabled="isSearching"
                >
                    <span x-show="! isSearching">Search</span>
                    <span x-show="isSearching">Searching...</span>
                </button>
            </div>

            <p x-show="searchError" x-text="searchError" class="mt-2 text-sm text-danger-600"></p>

            <div x-show="results.length" class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                <template x-for="result in results" x-bind:key="result.place_id">
                    <button
                        type="button"
                        class="block w-full border-b border-gray-100 px-3 py-2 text-left text-sm text-gray-700 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-200 dark:hover:bg-gray-800"
                        x-on:click="setLocation(result.lat, result.lon)"
                    >
                        <span x-text="result.display_name"></span>
                    </button>
                </template>
            </div>
        </div>

        <div
            x-ref="map"
            class="h-96 overflow-hidden rounded-xl border border-gray-300 bg-gray-100 dark:border-gray-700 dark:bg-gray-900"
            style="height: 420px; min-height: 420px; width: 100%;"
        ></div>

        <div
            x-show="mapError"
            x-cloak
            class="absolute inset-x-3 bottom-3 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 shadow dark:bg-danger-950 dark:text-danger-300"
            style="z-index: 1000;"
        >
            <span x-text="mapError"></span>
        </div>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Search for a place or click the map to set the latitude and longitude. The circle uses the configured radius.
    </p>
</div>

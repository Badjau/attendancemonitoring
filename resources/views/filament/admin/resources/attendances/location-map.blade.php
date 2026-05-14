@php
    $state = $getState();
    $latitude = (float) ($state['latitude'] ?? 0);
    $longitude = (float) ($state['longitude'] ?? 0);
    $location = $state['location'] ?? null;
    $employee = $state['employee'] ?? null;
    $googleMapsUrl = "https://www.google.com/maps?q={$latitude},{$longitude}";
@endphp

<div
    wire:ignore
    x-data="{
        map: null,
        marker: null,
        latitude: @js($latitude),
        longitude: @js($longitude),
        locationLabel: @js($location ?: 'Attendance location'),
        employeeName: @js($employee ?: 'Employee'),
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
            if (! this.$refs.map || ! window.L) return;

            const latLng = [this.latitude, this.longitude];

            this.map = L.map(this.$refs.map, {
                zoomControl: true,
                scrollWheelZoom: false,
            }).setView(latLng, 17);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(this.map);

            const popup = document.createElement('div');
            const employee = document.createElement('strong');
            const breakLine = document.createElement('br');
            const location = document.createTextNode(this.locationLabel);

            employee.textContent = this.employeeName;
            popup.append(employee, breakLine, location);

            this.marker = L.marker(latLng)
                .addTo(this.map)
                .bindPopup(popup);

            setTimeout(() => this.map.invalidateSize(), 150);
            setTimeout(() => this.map.invalidateSize(), 500);
        },
        init() {
            this.loadLeaflet()
                .then(() => this.initMap())
                .catch((error) => {
                    console.error('Unable to load attendance map.', error);
                    this.$refs.error.textContent = 'Unable to load the map. Check your internet connection or allow the Leaflet CDN.';
                    this.$refs.error.hidden = false;
                });
        },
    }"
    class="space-y-3"
>
    <div class="overflow-hidden rounded-xl border border-gray-300 bg-gray-100 dark:border-gray-700 dark:bg-gray-900">
        <div
            x-ref="map"
            class="h-80 w-full"
            style="height: 320px; min-height: 320px;"
        ></div>
    </div>

    <div
        x-ref="error"
        hidden
        class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-950 dark:text-danger-300"
    ></div>

    <div class="flex flex-col gap-2 text-sm text-gray-600 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <span class="font-medium text-gray-800 dark:text-gray-200">
                {{ number_format($latitude, 7) }}, {{ number_format($longitude, 7) }}
            </span>

            @if (filled($location))
                <span class="mx-2 text-gray-300 dark:text-gray-700">/</span>
                <span>{{ $location }}</span>
            @endif
        </div>

        <a
            href="{{ $googleMapsUrl }}"
            target="_blank"
            rel="noopener noreferrer"
            class="font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400"
        >
            Open in Google Maps
        </a>
    </div>
</div>

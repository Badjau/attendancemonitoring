import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { readFileSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';

const devServerHost = '20.20.52.75';
const devServerPort = 5174;
const allowedOrigins = [
    'https://attendancemonitoring.test',
    'https://20.20.52.75',
];
const sslKeyPath = 'C:/laragon/etc/ssl/laragon.key';
const sslCertPath = 'C:/laragon/etc/ssl/laragon.crt';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin/theme.css',
                'resources/js/app.js',
                'resources/js/filament-face-registration.js',
                'resources/js/filament-fingerprint-enrollment.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        origin: `https://${devServerHost}:${devServerPort}`,
        https: {
            key: readFileSync(sslKeyPath),
            cert: readFileSync(sslCertPath),
        },
        cors: {
            origin: allowedOrigins,
        },
        host: '0.0.0.0',
        port: devServerPort,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        hmr: {
            protocol: 'wss',
            host: devServerHost,
            port: devServerPort,
        },
    },
});

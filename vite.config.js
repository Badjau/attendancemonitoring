import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';

const sslKeyPath = 'C:/laragon/etc/ssl/laragon.key';
const sslCertPath = 'C:/laragon/etc/ssl/laragon.crt';
const hasSslCertificates = existsSync(sslKeyPath) && existsSync(sslCertPath);

const hostnameFromUrl = (value, fallback) => {
    try {
        return new URL(value).hostname || fallback;
    } catch {
        return fallback;
    }
};

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = env.APP_URL || 'https://attendancemonitoring.test';
    const devServerHost =
        env.VITE_DEV_SERVER_HOST || hostnameFromUrl(appUrl, '127.0.0.1');
    const devServerPort = Number(env.VITE_DEV_SERVER_PORT || 5174);
    const allowedOrigins = [
        'https://attendancemonitoring.test',
        appUrl,
        `https://${devServerHost}`,
    ];

    return {
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
        https: hasSslCertificates
            ? {
                  key: readFileSync(sslKeyPath),
                  cert: readFileSync(sslCertPath),
              }
            : undefined,
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
    };
});

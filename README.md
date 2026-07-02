# Attendance Monitoring System

Laravel 12 and Vue 3 attendance monitoring system with employee management, RFID attendance, face/camera attendance, and ZKTeco fingerprint scanner support.

## Documentation

- [Test Server Installation Guide](docs/TEST_SERVER_INSTALLATION.md)
- [Main Server Deployment Guide](docs/MAIN_SERVER_DEPLOYMENT.md)
- [System Documentation](docs/SYSTEM_DOCUMENTATION.md)
- [ZKTeco Fingerprint Setup](docs/ZKTECO_FINGERPRINT_SETUP.md)
- [Network Setup Guide](NETWORK_SETUP_GUIDE.md)
- [ZKTeco Bridge README](tools/ZKTecoBridge/README.md)

## Requirements

Install these before setting up the project:

- PHP 8.2 or newer
- Composer
- Node.js LTS and npm
- MySQL or MariaDB
- Git
- Laragon or another Apache/Nginx PHP stack for Windows deployments
- ZKTeco fingerprint scanner driver and SDK files when fingerprint attendance is used

## Quick Install

Open PowerShell and run:

```powershell
cd C:\laragon\www
git clone https://github.com/angelomiguelrcua2025-bot/attendancemonitoring.git
cd attendancemonitoring
git checkout fix/fingerprint_registration_issue-2026-05-21
composer install
npm install
npm run build
copy .env.example .env
php artisan key:generate
```

Create a database in MySQL:

```sql
CREATE DATABASE attendance_monitoring;
```

Update `.env`:

```env
APP_NAME="Attendance Monitoring"
APP_ENV=local
APP_DEBUG=false
APP_URL=https://YOUR_SERVER_IP_OR_DOMAIN

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_monitoring
DB_USERNAME=root
DB_PASSWORD=

ZKTECO_SCANNER_TOKEN=
ZKTECO_BRIDGE_URL=http://127.0.0.1:8765
```

Finish setup:

```powershell
php artisan migrate --seed
php artisan storage:link
php artisan optimize:clear
```

For normal server use, make sure Apache/Nginx points to:

```text
C:\laragon\www\attendancemonitoring\public
```

## Local Development

For active development:

```powershell
composer install
npm install
composer run dev
```

Then run your server (Laragon, Apache, Nginx, ...)

`composer run dev` starts Laravel, the queue listener, log tailing, and Vite together. For test or production servers, use `npm run build` instead of `npm run dev`.

## Server Update

When updating an existing server from GitHub:

```powershell
cd C:\laragon\www\attendancemonitoring
git pull
composer install
npm install
npm run build
php artisan migrate
php artisan storage:link
php artisan optimize:clear
```

For production/main server deployments, use optimized Composer install and cache Laravel after migration:

```powershell
composer install --no-dev --optimize-autoloader
npm.cmd run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## HTTPS And Camera Access

Camera attendance works reliably from other PCs only over HTTPS. Use the server IP or domain in `APP_URL`, then install/trust the Laragon certificate on client PCs if the browser warns about the certificate.

After changing `.env`, run:

```powershell
php artisan optimize:clear
```

## ZKTeco Fingerprint Setup

Fingerprint attendance needs the local bridge app because browsers cannot directly read USB fingerprint scanners.

1. Install the ZKTeco driver on the PC where the scanner is connected.
2. Generate a scanner token and set it in Laravel `.env` as `ZKTECO_SCANNER_TOKEN`.
3. Put the same token in `tools\ZKTecoBridge\App.config` under `ScannerToken`.
4. Set `ApiBaseUrl` in `tools\ZKTecoBridge\App.config` to the Laravel API, for example `https://YOUR_SERVER_IP_OR_DOMAIN/api/zkteco`.
5. Build or run the bridge as x86.
6. Register the browser launcher protocol once:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\ZKTecoBridge\RegisterProtocol.ps1
```

See [ZKTeco Fingerprint Setup](docs/ZKTECO_FINGERPRINT_SETUP.md) for the full enrollment and attendance workflow.

## Useful Commands

```powershell
php artisan about
php artisan migrate:status
php artisan test
npm run build
```

## Troubleshooting

If the browser tries to load Vite on port `5174`, remove the hot file and rebuild:

```powershell
Remove-Item public\hot -ErrorAction SilentlyContinue
npm run build
```

If storage images do not load:

```powershell
php artisan storage:link
```

If routes, config, or assets still look stale:

```powershell
php artisan optimize:clear
npm run build
```

If fingerprint scans fail, confirm the bridge is running, the scanner driver is installed, `ApiBaseUrl` is correct, and the bridge `ScannerToken` matches `ZKTECO_SCANNER_TOKEN`.

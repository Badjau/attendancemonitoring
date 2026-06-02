# Test Server Installation Guide

This guide installs the Attendance Monitoring System on a Windows test server PC using Laragon.

## 1. Install Required Software

Install these on the test server PC:

- Laragon
- Git
- Composer
- Node.js LTS
- ZKTeco fingerprint scanner driver, if fingerprint attendance is needed

Start Laragon and make sure Apache and MySQL can run.

## 2. Clone The Project

Open PowerShell and go to Laragon's web folder:

```powershell
cd C:\laragon\www
```

Clone the GitHub repository:

```powershell
git clone https://github.com/angelomiguelrcua2025-bot/attendancemonitoring.git
cd attendancemonitoring
git checkout fix/fingerprint_registration_issue-2026-05-21
```

## 3. Install Project Dependencies

Install PHP dependencies:

```powershell
composer install
```

Install JavaScript dependencies:

```powershell
npm install
```

Build production assets:

```powershell
npm run build
```

Do not use `npm run dev` for normal test server use. The app should load files from `/build`, not from Vite port `5174`.

## 4. Create The Environment File

Create `.env`:

```powershell
copy .env.example .env
php artisan key:generate
```

Edit `.env` and set the test server URL and database settings.

Example for HTTPS by IP:

```env
APP_ENV=local
APP_DEBUG=false
APP_URL=https://20.20.52.75

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_monitoring
DB_USERNAME=root
DB_PASSWORD=
```

Replace `20.20.52.75` with the test server PC's LAN IP address.

## 5. Create The Database

Open Laragon's database tool or MySQL console and create the database:

```sql
CREATE DATABASE attendance_monitoring;
```

Then run:

```powershell
php artisan migrate --seed
php artisan storage:link
php artisan optimize:clear
```

## 6. Configure HTTPS For Camera Access

Camera access on other PCs requires HTTPS. HTTP may load the page, but browsers can block camera access.

Recommended URL:

```text
https://TEST_SERVER_IP
```

Example:

```text
https://20.20.52.75
```

If other PCs show `ERR_CERT_AUTHORITY_INVALID`, install the Laragon certificate from the test server PC:

```text
C:\laragon\etc\ssl\laragon.crt
```

Import it on each client PC into:

```text
Trusted Root Certification Authorities
```

Then fully close and reopen the browser.

## 7. Configure Apache To Serve By IP

If `https://TEST_SERVER_IP` shows the default Laragon page or a 404, update the Laragon Apache virtual host for this project.

Open:

```text
C:\laragon\etc\apache2\sites-enabled\auto.attendancemonitoring.test.conf
```

Make sure both port `80` and port `443` virtual hosts include the server IP as a `ServerAlias`.

Example:

```apache
ServerName attendancemonitoring.test
ServerAlias *.attendancemonitoring.test 20.20.52.75
```

Restart Apache from Laragon after changing this file.

## 8. Start The Web App

For normal use:

1. Start Laragon.
2. Start Apache.
3. Start MySQL.
4. Open:

```text
https://TEST_SERVER_IP
```

Example:

```text
https://20.20.52.75
```

If you changed `.env` or pulled updates, run:

```powershell
php artisan optimize:clear
npm run build
```

## 9. Fingerprint Scanner Setup

The browser cannot directly read the ZKTeco scanner. The local ZKTeco Bridge app must run on the PC where the scanner is connected.

Update:

```text
tools\ZKTecoBridge\App.config
```

Set `ApiBaseUrl` to the test server API:

```xml
<add key="ApiBaseUrl" value="https://TEST_SERVER_IP/api/zkteco" />
```

Example:

```xml
<add key="ApiBaseUrl" value="https://20.20.52.75/api/zkteco" />
```

Make sure `ScannerToken` matches `ZKTECO_SCANNER_TOKEN` in `.env`.

Build/run the bridge as x86. If the bridge is already published, run the published executable:

```text
tools\zkteco-bridge\bin\Debug\net9.0\win-x86\publish\ZktecoFingerprintBridge.exe
```

When the bridge is running:

- Manual fingerprint button works.
- Automatic fingerprint attendance works when a registered finger is placed on the scanner.
- RFID still works after fingerprint scans.

## 10. Updating The Test Server

To pull future updates:

```powershell
cd C:\laragon\www\attendancemonitoring
git pull
composer install
npm install
npm run build
php artisan migrate
php artisan optimize:clear
```

## 11. Troubleshooting

### Browser loads Vite `:5174`

Remove the Vite hot file and rebuild:

```powershell
Remove-Item public\hot -ErrorAction SilentlyContinue
npm run build
```

### Camera does not work on another PC

Use HTTPS and trust the Laragon certificate on that PC.

### Mixed content error

Make sure `.env` uses HTTPS:

```env
APP_URL=https://TEST_SERVER_IP
```

Then run:

```powershell
php artisan optimize:clear
```

### Storage images do not load

Run:

```powershell
php artisan storage:link
```

### Fingerprint scanner does not respond

Check:

- ZKTeco Bridge is running.
- ZKTeco driver is installed.
- `ApiBaseUrl` points to the test server.
- `ScannerToken` matches `.env`.
- The scanner is connected to the same PC running the bridge.

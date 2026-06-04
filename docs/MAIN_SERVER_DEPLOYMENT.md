# Main Server Deployment Guide

This guide installs the Attendance Monitoring System on the main Windows server using Laragon.

Use this when moving from the test server or developer PC to the real server used by staff.

## 1. Prepare The Main Server

Install these on the main server:

- Laragon
- Git
- Composer
- Node.js LTS
- ZKTeco fingerprint scanner driver, if the scanner is connected to this server

Start Laragon and confirm Apache and MySQL can run.

## 2. Get The Project

Recommended option: use Git so future updates are easier.

Open PowerShell:

```powershell
cd C:\laragon\www
git clone https://github.com/angelomiguelrcua2025-bot/attendancemonitoring.git
cd attendancemonitoring
```

If you need a specific branch, check it out after cloning:

```powershell
git checkout fix/fingerprint_registration_issue-2026-05-21
```

If the project is already copied to the server, go directly into the project folder:

```powershell
cd C:\laragon\www\attendancemonitoring
```

Copy-paste is okay for the first transfer, but Git is better for the main server because updates can be pulled with one command later.

## 3. Install Dependencies

Install PHP dependencies:

```powershell
composer install --no-dev --optimize-autoloader
```

Install JavaScript dependencies:

```powershell
npm install
```

Build production assets:

```powershell
npm.cmd run build
```

Use `npm.cmd` in PowerShell if `npm run build` is blocked by the Windows execution policy.

Do not use `npm run dev` for the main server.

## 4. Create The Environment File

Create `.env`:

```powershell
copy .env.example .env
php artisan key:generate
```

Edit `.env` and set the main server values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://MAIN_SERVER_IP_OR_DOMAIN

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_monitoring
DB_USERNAME=root
DB_PASSWORD=
```

Replace `MAIN_SERVER_IP_OR_DOMAIN` with the real server IP or domain.

Example:

```env
APP_URL=https://20.20.52.47
```

## 5. Create The Database

Open Laragon's database tool, phpMyAdmin, Adminer, or MySQL console.

Create the database:

```sql
CREATE DATABASE attendance_monitoring;
```

Then run:

```powershell
php artisan migrate --seed
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 6. Configure Apache

The web server must point to the Laravel `public` folder, not the project root.

Correct document root:

```text
C:\laragon\www\attendancemonitoring\public
```

Open the Laragon Apache virtual host file:

```text
C:\laragon\etc\apache2\sites-enabled\auto.attendancemonitoring.test.conf
```

Use this pattern:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/laragon/www/attendancemonitoring/public"
    ServerName attendancemonitoring.test
    ServerAlias MAIN_SERVER_IP_OR_DOMAIN

    <Directory "C:/laragon/www/attendancemonitoring/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

<VirtualHost *:443>
    DocumentRoot "C:/laragon/www/attendancemonitoring/public"
    ServerName attendancemonitoring.test
    ServerAlias MAIN_SERVER_IP_OR_DOMAIN

    SSLEngine on
    SSLCertificateFile "C:/laragon/etc/ssl/attendancemonitoring.crt"
    SSLCertificateKeyFile "C:/laragon/etc/ssl/attendancemonitoring.key"

    <Directory "C:/laragon/www/attendancemonitoring/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Replace `MAIN_SERVER_IP_OR_DOMAIN`.

Example:

```apache
ServerAlias 20.20.52.47
```

Restart Apache from Laragon after changing this file.

## 7. Configure HTTPS

HTTPS is required for browser camera features and is recommended for the fingerprint workflows.

Open:

```text
https://MAIN_SERVER_IP_OR_DOMAIN
```

If client PCs show a certificate warning, install the Laragon certificate on each client PC into:

```text
Trusted Root Certification Authorities
```

Then fully close and reopen the browser.

## 8. Optional: Auto-Open The App When Laragon Starts

Open:

```text
C:\laragon\usr\Procfile
```

Add:

```text
Attendance Monitoring: autorun cmd /c timeout /t 5 /nobreak >nul & start "" "https://MAIN_SERVER_IP_OR_DOMAIN"
```

Example:

```text
Attendance Monitoring: autorun cmd /c timeout /t 5 /nobreak >nul & start "" "https://20.20.52.47"
```

Now opening Laragon will also open the app in the browser.

## 9. Fingerprint Bridge

The ZKTeco Bridge must run on the PC where the fingerprint scanner is connected.

Edit:

```text
tools\ZKTecoBridge\App.config
```

Set the API URL:

```xml
<add key="ApiBaseUrl" value="https://MAIN_SERVER_IP_OR_DOMAIN/api/zkteco" />
```

Make sure `ScannerToken` matches `ZKTECO_SCANNER_TOKEN` in the server `.env`.

Published bridge executable:

```text
tools\zkteco-bridge\bin\Debug\net9.0\win-x86\publish\ZktecoFingerprintBridge.exe
```

## 10. Updating The Main Server

If the main server uses Git, deploy future updates with:

```powershell
cd C:\laragon\www\attendancemonitoring
git pull
composer install --no-dev --optimize-autoloader
npm install
npm.cmd run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If Git reports local changes on the main server, do not overwrite them until you know what changed:

```powershell
git status
```

If the server was created by copy-paste and does not use Git yet, the simplest update method is to copy the new project files again, then rerun:

```powershell
composer install --no-dev --optimize-autoloader
npm install
npm.cmd run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Restart Apache after server configuration changes.

## 11. Quick Checks

Check Laravel can boot:

```powershell
php artisan about
```

Check database migrations:

```powershell
php artisan migrate:status
```

Check the app URL:

```powershell
curl.exe -k -I https://MAIN_SERVER_IP_OR_DOMAIN
```

Expected result:

```text
HTTP/1.1 200 OK
```

or:

```text
HTTP/1.1 302 Found
Location: https://MAIN_SERVER_IP_OR_DOMAIN/unlock
```

The `302` response is normal when the timeclock is locked.

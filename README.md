# Attendance Monitoring Setup Guide

This project is a Laravel attendance monitoring system with:

- Filament admin pages
- Inertia/Vue timeclock screens
- camera and face recognition flows
- RFID/keypad attendance
- ZKTeco fingerprint scanner support through a local Windows agent

The usual Windows project path is:

```bat
C:\laragon\www\attendancemonitoring
```

Use this README as the practical setup guide. The most important choice is the mode you are running in:

- **Local dev testing**: everything is on one PC, opened through `127.0.0.1`.
- **Server test mode**: one PC hosts the app, and other devices connect through the server PC's LAN IP.

Do not mix the two casually. Most connection issues come from using `localhost` from the wrong machine.

## Quick URL Rules

`localhost` and `127.0.0.1` always mean "this device", not "the server".

| Situation | Use |
| --- | --- |
| Local Laravel dev server | `http://127.0.0.1:8000` |
| Local ZKTeco agent | `http://127.0.0.1:8765` |
| Local face service | `https://127.0.0.1:8001` |
| Server test app over LAN | `https://SERVER_IP` |
| Server test face service | `https://SERVER_IP:8001/health` |
| Laragon local domain, same server PC only | `https://attendancemonitoring.test` |
| Vite dev server | `https://SERVER_IP:5174` or `https://127.0.0.1:5174` |

If another phone, tablet, or PC is connecting to the test server, it must use the server IP, for example:

```text
https://20.20.52.75
```

It should not use:

```text
https://localhost
http://localhost:8000
https://127.0.0.1
```

Those addresses point back to the client device itself.

## Prerequisites

Install these on the main development or test server PC:

- Laragon, normally at `C:\laragon`
- PHP 8.2 or newer
- Composer
- Node.js LTS and npm
- MySQL or MariaDB
- Git
- Python 3.10 or 3.11 for the face service
- ZKTeco scanner drivers and SDK DLLs if fingerprint scanning is used

Check versions from Command Prompt:

```bat
php -v
composer -V
node -v
npm -v
python --version
```

Avoid Python 3.14 for the face service for now. DeepFace/TensorFlow dependencies are usually safer on Python 3.10 or 3.11.

## First-Time App Setup

From the project root:

```bat
cd C:\laragon\www\attendancemonitoring
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Create the database in MySQL or MariaDB:

```sql
CREATE DATABASE attendance_monitoring;
```

Set the database values in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_monitoring
DB_USERNAME=root
DB_PASSWORD=
```

Then run:

```bat
php artisan migrate --seed
php artisan storage:link
php artisan optimize:clear
```

If your local MySQL root password is `root`, use `DB_PASSWORD=root`. Laragon commonly uses a blank root password unless you changed it.

## Mode 1: Local Dev Testing

Use this mode when everything is on one PC:

- Laravel app
- browser
- database
- camera
- RFID reader
- fingerprint scanner
- ZKTeco local agent
- face service

Suggested `.env` values:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

ZKTECO_SCANNER_TOKEN=your-local-token
ZKTECO_BRIDGE_URL=http://127.0.0.1:8765
ZKTECO_LOCAL_BRIDGE_URL=http://127.0.0.1:8765

FACE_SERVICE_URL=https://127.0.0.1:8001
VITE_FACE_SERVICE_URL=https://127.0.0.1:8001
ALLOWED_ORIGINS=http://127.0.0.1:8000,http://localhost:8000,https://127.0.0.1,https://localhost
LARAVEL_BASE_URL=http://127.0.0.1:8000
```

Start Laravel, the queue listener, log tailing, and Vite:

```bat
cd C:\laragon\www\attendancemonitoring
composer run dev
```

Open:

```text
http://127.0.0.1:8000
```

Camera access is allowed by browsers on `localhost` / `127.0.0.1`, even when the page is HTTP, because loopback is treated as a secure context.

### Local Face Service

Set it up once:

```bat
cd C:\laragon\www\attendancemonitoring\face_service
python -m venv .venv
.venv\Scripts\activate
pip install --upgrade pip
pip install -r requirements.txt
```

Start it locally:

```bat
cd C:\laragon\www\attendancemonitoring\face_service
.venv\Scripts\activate
uvicorn app.main:app --host 127.0.0.1 --port 8001 --ssl-keyfile C:/laragon/etc/ssl/laragon.key --ssl-certfile C:/laragon/etc/ssl/laragon.crt
```

Health check:

```text
https://127.0.0.1:8001/health
```

The face service is started with HTTPS in this local setup. `http://127.0.0.1:8001/health` can fail even when the service is healthy.

You can also test it with:

```bat
curl -k https://127.0.0.1:8001/health
```

The first face enrollment or recognition request can take longer because model weights may be downloaded or loaded.

### Local Fingerprint And RFID

The browser cannot directly access the ZKTeco USB scanner. The scanner PC must run the local agent at:

```text
http://127.0.0.1:8765
```

Check:

```text
http://127.0.0.1:8765/health
http://127.0.0.1:8765/status
```

RFID readers usually behave like keyboard input. They only work on the PC where the reader is plugged in and the browser page is focused.

See:

- `tools\ZktecoLocalAgent\README.md`
- `docs\ZKTECO_FINGERPRINT_SETUP.md`

## Mode 2: Server Test Mode

Use this mode when one Windows PC hosts the app and other devices connect over the same network.

This is the best mode for testing cameras from phones, laptops, tablets, or other PCs.

### 1. Find The Server IP

On the server PC:

```bat
ipconfig
```

Look for the active adapter's IPv4 address. In the examples below, replace `SERVER_IP` with that value:

```text
20.20.52.75
```

### 2. Configure `.env`

Example:

```env
APP_ENV=local
APP_DEBUG=false
APP_URL=https://SERVER_IP

ZKTECO_SCANNER_TOKEN=your-shared-test-token
ZKTECO_BRIDGE_URL=http://127.0.0.1:8765
ZKTECO_LOCAL_BRIDGE_URL=http://127.0.0.1:8765

FACE_SERVICE_URL=https://SERVER_IP:8001
VITE_FACE_SERVICE_URL=https://SERVER_IP:8001
```

Real example:

```env
APP_URL=https://20.20.52.75
FACE_SERVICE_URL=https://20.20.52.75:8001
VITE_FACE_SERVICE_URL=https://20.20.52.75:8001
```

After changing `.env`:

```bat
php artisan optimize:clear
```

### 3. Build Assets

For normal server testing, use built assets instead of Vite:

```bat
npm run build
php artisan optimize:clear
php artisan view:cache
```

If the browser keeps trying to load Vite on port `5174` when Vite is not running, remove the hot file and rebuild:

```bat
del public\hot
npm run build
```

### 4. Serve Through Laragon Apache

Normal server test mode uses Laragon Apache, not `php artisan serve`.

Start Laragon, then start:

- Apache
- MySQL

Open on the server or another device:

```text
https://SERVER_IP
```

Example:

```text
https://20.20.52.75
```

If the IP URL shows the Laragon default page or a 404, update the Apache virtual host for this project so the server IP is included as a `ServerAlias`.

Typical file:

```text
C:\laragon\etc\apache2\sites-enabled\auto.attendancemonitoring.test.conf
```

Example:

```apache
ServerName attendancemonitoring.test
ServerAlias *.attendancemonitoring.test 20.20.52.75
```

Restart Apache after changing the file.

### 5. HTTPS For Cameras

Remote camera access needs HTTPS. HTTP may load the page, but browsers can block camera APIs.

For quick testing, accepting the self-signed certificate warning is often enough. For cleaner testing, install the Laragon certificate from the server PC:

```text
C:\laragon\etc\ssl\laragon.crt
```

Import it on client PCs into:

```text
Trusted Root Certification Authorities
```

Then fully close and reopen the browser.

### 6. Start Face Service For LAN

On the server PC:

```bat
cd C:\laragon\www\attendancemonitoring\face_service
.venv\Scripts\activate
uvicorn app.main:app --host 0.0.0.0 --port 8001 --ssl-keyfile C:/laragon/etc/ssl/laragon.key --ssl-certfile C:/laragon/etc/ssl/laragon.crt
```

Health check from the server or a client device:

```text
https://SERVER_IP:8001/health
```

Example:

```bat
curl -k https://20.20.52.75:8001/health
```

Expected response includes:

```json
{
  "ok": true
}
```

### 7. Open Firewall Ports

On the server PC, allow inbound:

- TCP `80`
- TCP `443`
- TCP `8001` if remote browsers call the face service
- TCP `5174` only if you intentionally run Vite over LAN
- TCP `8000` only if you intentionally share `php artisan serve`

The HTTPS installer under `tools\` can configure Apache/HTTPS and open ports `80` and `443`, but still check `8001` if face recognition fails from other devices.

## Device Rules

This part matters a lot:

- Cameras are local to the browser/device opening the page.
- RFID readers are local to the PC where the reader is plugged in.
- ZKTeco fingerprint scanners are local to the PC running the scanner and local agent.
- A remote browser cannot use a USB scanner plugged into the server unless the browser is also running on the server PC.

So for server test mode:

- Other devices can open `https://SERVER_IP` and test their own cameras.
- A scanner plugged into the server PC works only from the browser on the server PC.
- A scanner plugged into a client PC needs the ZKTeco local agent installed on that client PC.
- An RFID reader works wherever it is plugged in, as long as the browser page is focused.

For a client PC with its own fingerprint scanner, configure the local agent with:

```text
ApiBaseUrl = https://SERVER_IP/api/zkteco
LocalListenUrl = http://127.0.0.1:8765
```

The agent's `ScannerToken` must exactly match `ZKTECO_SCANNER_TOKEN` in the Laravel `.env`.

## ZKTeco Local Agent

The current local agent lives under:

```text
tools\ZktecoLocalAgent
```

It is a Windows-only .NET agent that listens locally on:

```text
http://127.0.0.1:8765
```

Required SDK DLLs must be placed in:

```text
tools\ZktecoLocalAgent\lib\x86
```

Required files:

- `libzkfpcsharp.dll`
- `libzkfp.dll`
- `ZKFPCap.dll`

Publish command:

```powershell
dotnet publish .\tools\ZktecoLocalAgent\ZktecoLocalAgent.csproj -c Release -r win-x86 --self-contained true -o .\tools\ZktecoLocalAgent\publish
```

Install example:

```powershell
cd C:\laragon\www\attendancemonitoring
.\tools\ZktecoLocalAgent\Installer\install-agent.ps1 `
  -ApiBaseUrl "https://SERVER_IP/api/zkteco" `
  -ScannerToken "your-shared-test-token" `
  -DeviceSerial "SCANNER-PC-01"
```

For localhost-only development, use:

```text
http://127.0.0.1:8000/api/zkteco
```

For server test mode, use:

```text
https://SERVER_IP/api/zkteco
```

## Daily Startup

### Local Dev

Start these on the same PC:

```bat
composer run dev
```

Then start the face service if needed:

```bat
cd C:\laragon\www\attendancemonitoring\face_service
.venv\Scripts\activate
uvicorn app.main:app --host 127.0.0.1 --port 8001 --ssl-keyfile C:/laragon/etc/ssl/laragon.key --ssl-certfile C:/laragon/etc/ssl/laragon.crt
```

Start the ZKTeco local agent if fingerprint scanning is needed.

Open:

```text
http://127.0.0.1:8000
```

### Server Test

On the server PC:

1. Start Laragon.
2. Start MySQL.
3. Start Apache.
4. Start the face service if camera/face recognition is needed.
5. Start the ZKTeco local agent only if a scanner is plugged into this PC.

On client devices:

1. Open `https://SERVER_IP`.
2. Accept or trust the certificate.
3. Allow camera permission.
4. Start the local ZKTeco agent only if that client PC has its own scanner.

## Useful Verification Commands

From the Laravel project root:

```bat
php artisan about
php artisan migrate:status
php artisan test tests\Feature\FaceRoutesTest.php
npm run build
```

If Symfony or Pint tries to write temp files under `C:\Windows`, set local temp paths first:

```bat
set TMP=C:\laragon\www\attendancemonitoring\storage\framework\testing
set TEMP=C:\laragon\www\attendancemonitoring\storage\framework\testing
php artisan test tests\Feature\FaceRoutesTest.php
```

Known notes for this workspace:

- The full Laravel test suite has been runnable but not fully green in this environment.
- `npm run build` and `npm run format` may fail in some runners before Vite/Prettier starts because of a Node crypto assertion.
- Face service tests may fail in restricted runners if Python cannot get random bytes during startup.

## Troubleshooting

### `https://SERVER_IP` Cannot Connect

Check the IP:

```bat
ipconfig
```

From a client device:

```bat
ping SERVER_IP
```

Check whether Apache is listening:

```bat
netstat -ano | findstr ":80"
netstat -ano | findstr ":443"
```

If nothing is listening on `443`, Apache is not running or HTTPS is not configured.

### `localhost` Cannot Connect

This is usually expected unless you are on the same PC and something is actually listening there.

- `http://127.0.0.1:8000` only works while `php artisan serve` is running on that PC.
- `https://localhost` only works if Apache has a localhost HTTPS site configured.
- Other devices must use `https://SERVER_IP`.

### Browser Shows A Certificate Warning

Expected for a self-signed Laragon certificate.

For testing, continue past the warning. For smoother client testing, install/trust `C:\laragon\etc\ssl\laragon.crt` on the client device.

### Assets Or Login Behave Strangely

Clear caches and rebuild:

```bat
php artisan optimize:clear
npm run build
php artisan view:cache
```

If Vite is referenced when it should not be:

```bat
del public\hot
npm run build
```

### Database Connection Fails

Confirm MySQL is running and `.env` matches the local database:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_monitoring
DB_USERNAME=root
DB_PASSWORD=
```

Then:

```bat
php artisan migrate:status
```

### Face Recognition Fails

Check the service:

```bat
curl -k https://SERVER_IP:8001/health
```

Confirm `.env`:

```env
FACE_SERVICE_URL=https://SERVER_IP:8001
VITE_FACE_SERVICE_URL=https://SERVER_IP:8001
```

Then:

```bat
php artisan optimize:clear
```

If the camera is blocked, use HTTPS and allow camera permission in the browser.

### Fingerprint Scanner Does Not Respond

Check on the scanner PC:

- the ZKTeco driver is installed
- the SDK DLLs are present
- `ZktecoLocalAgent.exe` is running
- `http://127.0.0.1:8765/health` responds
- the agent `ApiBaseUrl` points to the right Laravel app
- the agent `ScannerToken` matches `ZKTECO_SCANNER_TOKEN`

### IP Address Changed

If the server IP changes, update:

- `.env` `APP_URL`
- `.env` `FACE_SERVICE_URL`
- `.env` `VITE_FACE_SERVICE_URL`
- Apache `ServerAlias`
- any ZKTeco local agent `ApiBaseUrl`

Then run:

```bat
php artisan optimize:clear
npm run build
php artisan view:cache
```

Restart Apache and the face service.

## Quick Recovery

Use this when the LAN site is in a weird state:

```bat
cd C:\laragon\www\attendancemonitoring
php artisan optimize:clear
npm run build
php artisan view:cache
```

Start Laragon Apache and MySQL, then open:

```text
https://SERVER_IP
```

If face recognition is needed:

```bat
cd C:\laragon\www\attendancemonitoring\face_service
.venv\Scripts\activate
uvicorn app.main:app --host 0.0.0.0 --port 8001 --ssl-keyfile C:/laragon/etc/ssl/laragon.key --ssl-certfile C:/laragon/etc/ssl/laragon.crt
```

Then check:

```text
https://SERVER_IP:8001/health
```

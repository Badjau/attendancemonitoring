Attendance Monitoring Setup And Connection Guide
This guide is for the Laravel attendance monitoring system installed at:

C:\laragon\www\attendancemonitoring
The current server URL in .env is:

APP_URL=https://20.20.52.71
LARAVEL_BASE_URL=https://20.20.52.71
FACE_SERVICE_URL=https://20.20.52.71:8001
VITE_FACE_SERVICE_URL=https://20.20.52.71:8001
Use https://20.20.52.71/ when the app is being served by Laragon Apache over HTTPS. Do not use localhost from another PC. On every computer, localhost means "this same computer", not the attendance server.

What Must Be Running
For https://20.20.52.71/ to work, all of these must be true:

The server PC really has the IP address 20.20.52.71.
Laragon Apache is configured to serve this project from public.
Apache is running and listening on ports 80 and 443.
Windows Firewall allows inbound TCP ports 80 and 443.
.env uses APP_URL=https://20.20.52.71.
Laravel caches were cleared after .env changes.
If face recognition is used, the Python face service is running on https://20.20.52.71:8001.
If any item is missing, the browser may show "cannot connect", "connection refused", "site cannot be reached", certificate warnings, or camera/face-recognition failures.

Important URL Rules
Use these URLs depending on how the app is running:

Situation	URL
Main LAN/server setup through Laragon Apache	https://20.20.52.71/
Same server PC through Laragon domain	https://attendancemonitoring.test/
Laravel dev server, only if started with php artisan serve	http://127.0.0.1:8000/
Laravel dev server shared on LAN	http://20.20.52.71:8000/
Face service health check	https://20.20.52.71:8001/health
Vite development server	https://20.20.52.71:5174/
Do not expect https://localhost/ to work unless Apache has a localhost HTTPS site configured. Do not expect http://localhost:8000/ to work unless php artisan serve is running on the same PC.

Prerequisites
Install these on the server PC:

Laragon, normally installed at C:\laragon
PHP 8.2 or newer
Composer
Node.js LTS and npm
MySQL or MariaDB
Git
Python 3.10 or 3.11 for the face service
ZKTeco scanner drivers/SDK only if fingerprint scanning is used
Check the versions from Command Prompt:

php -v
composer -V
node -v
npm -v
python --version
Avoid Python 3.14 for the face service for now. DeepFace and TensorFlow dependencies are safer on Python 3.10 or 3.11.

First-Time Laravel Setup
Open Command Prompt in the project root:

cd C:\laragon\www\attendancemonitoring
Install PHP and Node dependencies:

composer install
npm install
npm run build
If .env does not exist yet:

copy .env.example .env
php artisan key:generate
Create the database in MySQL:

CREATE DATABASE attendance_monitoring;
Set the database values in .env:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_monitoring
DB_USERNAME=root
DB_PASSWORD=root
Run migrations and storage setup:

php artisan migrate --seed
php artisan storage:link
php artisan optimize:clear
Configure The Correct Server IP
First confirm the server PC IP address:

ipconfig
Look for the active adapter's IPv4 address. If it is still 20.20.52.71, keep these values in .env:

APP_URL=https://20.20.52.71
LARAVEL_BASE_URL=https://20.20.52.71
FACE_SERVICE_URL=https://20.20.52.71:8001
VITE_FACE_SERVICE_URL=https://20.20.52.71:8001
If the IP changed, replace 20.20.52.71 everywhere above with the new server IP.

After changing .env, always clear Laravel caches:

php artisan optimize:clear
Configure HTTPS In Laragon
The project includes an installer that configures Laragon Apache for HTTP and HTTPS access. Run it on the server PC as Administrator.

Option 1, from File Explorer:

Right-click C:\laragon\www\attendancemonitoring\tools\install-https-autostart.cmd
Run as administrator
Option 2, from an Administrator PowerShell:

cd C:\laragon\www\attendancemonitoring
.\tools\install-https-autostart.ps1 -IpAddress 20.20.52.71
The installer does this:

Creates a self-signed SSL certificate for the IP address.
Creates Apache virtual hosts for ports 80 and 443.
Points Apache to C:\laragon\www\attendancemonitoring\public.
Updates .env APP_URL.
Clears Laravel caches.
Opens Windows Firewall ports 80 and 443.
Registers the scheduled task AttendanceMonitoringStartHttps.
Starts an Apache watchdog that reopens Apache if it closes.
After the installer finishes, open:

https://20.20.52.71/
A certificate warning is expected because the certificate is self-signed. For testing, continue past the warning. For production, trust the certificate on client PCs or use a certificate issued by a trusted certificate authority.

Start Or Restart The Web App
Normal LAN/server mode uses Laragon Apache:

Open Laragon.
Start Apache.
Start MySQL.
Open https://20.20.52.71/.
If Apache does not start, run this in an Administrator Command Prompt to test the Apache config:

C:\laragon\bin\apache\httpd-*\bin\httpd.exe -t
If the wildcard path does not work in your shell, find the exact Apache folder under:

C:\laragon\bin\apache
Then run its bin\httpd.exe -t.

Local Development Mode
Use development mode only while editing code.

Start Laravel, the queue listener, log tailing, and Vite together:

cd C:\laragon\www\attendancemonitoring
composer run dev
The Composer dev script starts php artisan serve, queue:listen, Laravel log tailing, and npm run dev.

Important development notes:

php artisan serve normally serves http://127.0.0.1:8000.
That is separate from https://20.20.52.71/.
Vite uses HTTPS on port 5174.
If a browser tries to load stale assets from 5174 when you are not running Vite, delete public\hot and rebuild.
del public\hot
npm run build
For normal server use, prefer:

npm run build
php artisan optimize:clear
Face Recognition Service
The Laravel app does not perform recognition by itself. The Python FastAPI service must run separately.

Set up the face service:

cd C:\laragon\www\attendancemonitoring\face_service
python -m venv .venv
.venv\Scripts\activate
pip install --upgrade pip
pip install -r requirements.txt
Run tests:

pytest tests
Start the service on all network interfaces with HTTPS:

cd C:\laragon\www\attendancemonitoring\face_service
.venv\Scripts\activate
uvicorn app.main:app --host 0.0.0.0 --port 8001 --ssl-keyfile C:/laragon/etc/ssl/laragon.key --ssl-certfile C:/laragon/etc/ssl/laragon.crt
Keep this Command Prompt window open.

Health check:

https://20.20.52.71:8001/health
You can also test with curl:

curl -k https://20.20.52.71:8001/health
Expected response includes:

{
  "ok": true
}
The first enrollment or recognition request may take longer because DeepFace can download model weights.

Camera And HTTPS
Modern browsers require a secure context for reliable camera access. Use HTTPS for face enrollment and face attendance:

https://20.20.52.71/
On each client PC:

Open the site.
Accept or trust the certificate warning.
Allow camera permission when the browser asks.
Test the camera with the Windows Camera app if the browser cannot see it.
If the page is opened with plain HTTP from another PC, camera access may fail.

Access From Other PCs
On a client PC connected to the same LAN:

Confirm the client can reach the server:

ping 20.20.52.71
Open the app:

https://20.20.52.71/
If the browser shows a certificate warning, continue for testing or install/trust the local certificate.

Do not use these from a client PC:

https://localhost/
http://localhost:8000/
https://127.0.0.1/
Those addresses point to the client PC itself.

Fingerprint And ZKTeco Local Agent
Browsers cannot directly access most USB fingerprint scanners. This project uses a local agent/bridge for scanner access.

Relevant .env values:

ZKTECO_SCANNER_TOKEN=your-token
ZKTECO_BRIDGE_URL=http://127.0.0.1:8765
ZKTECO_LOCAL_BRIDGE_URL=http://127.0.0.1:8765
General setup:

Install the ZKTeco scanner driver on the PC where the scanner is plugged in.
Run the local ZKTeco agent on that same PC.
Keep ZKTECO_SCANNER_TOKEN in Laravel and the local agent config the same.
Use a unique device serial/name for each scanner PC.
Confirm the agent is listening at http://127.0.0.1:8765 on the scanner PC.
See docs\ZKTECO_FINGERPRINT_SETUP.md and tools\ZktecoLocalAgent\README.md for the detailed enrollment workflow.

Daily Startup Checklist
On the server PC:

Start Laragon.
Start MySQL.
Start Apache.
Open https://20.20.52.71/.
Start the face service if face attendance is used.
Start the ZKTeco local agent if fingerprint scanning is used on this PC.
On client PCs:

Open https://20.20.52.71/.
Allow camera permission if using face attendance.
Start the local ZKTeco agent if a scanner is connected to that client PC.
Verification Commands
Run these from the Laravel project root:

cd C:\laragon\www\attendancemonitoring
php artisan about
php artisan migrate:status
php artisan test
npm run build
Run the focused face route test:

php artisan test tests\Feature\FaceRoutesTest.php
If Symfony tries to write temp files to C:\Windows, set local temp paths first:

set TMP=C:\laragon\www\attendancemonitoring\storage\framework\testing
set TEMP=C:\laragon\www\attendancemonitoring\storage\framework\testing
php artisan test tests\Feature\FaceRoutesTest.php
Troubleshooting
https://20.20.52.71/ Cannot Connect
Check that the IP is still correct:

ipconfig
Check network reachability from a client PC:

ping 20.20.52.71
Check that ports are listening on the server:

netstat -ano | findstr ":80"
netstat -ano | findstr ":443"
If nothing is listening on :443, Apache is not running or HTTPS is not configured. Re-run the installer as Administrator:

cd C:\laragon\www\attendancemonitoring
.\tools\install-https-autostart.ps1 -IpAddress 20.20.52.71
Then restart Apache from Laragon and try again.

localhost Cannot Connect
This is usually expected.

localhost only works on the same PC and only if something is listening on the requested port. For this project:

https://localhost/ is not the configured production URL.
http://localhost:8000/ only works while php artisan serve is running on that same PC.
Other PCs must use https://20.20.52.71/.
Browser Shows A Certificate Warning
This is expected with the local self-signed certificate created by the installer. Continue for testing, or install/trust the certificate on each client PC.

Page Opens But Login Or Assets Behave Strangely
Clear Laravel caches and rebuild assets:

cd C:\laragon\www\attendancemonitoring
php artisan optimize:clear
npm run build
If the browser tries to load Vite from port 5174 when Vite is not running:

del public\hot
npm run build
Database Connection Fails
Confirm MySQL is running in Laragon and .env matches the local database:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_monitoring
DB_USERNAME=root
DB_PASSWORD=root
Then run:

php artisan migrate:status
Face Recognition Fails
Confirm the face service is running:

curl -k https://20.20.52.71:8001/health
Confirm .env has:

FACE_SERVICE_URL=https://20.20.52.71:8001
VITE_FACE_SERVICE_URL=https://20.20.52.71:8001
Then clear caches:

php artisan optimize:clear
If the browser blocks the camera, open the app with HTTPS and allow camera permission.

Firewall Blocks Other PCs
Open Windows Defender Firewall inbound rules for:

TCP 80
TCP 443
TCP 8001 if client browsers call the face service directly
TCP 5174 only for development with Vite
TCP 8000 only if using php artisan serve --host=0.0.0.0 --port=8000
The HTTPS installer creates rules for 80 and 443. Add 8001 manually if face-service access from client browsers is blocked.

IP Address Changed
If DHCP assigns a new IP, https://20.20.52.71/ will stop working.

Fix options:

Set a static IP or DHCP reservation for the server PC.
Re-run the HTTPS installer with the new IP.
Update .env URLs to the new IP.
Run php artisan optimize:clear.
Restart Apache and the face service.
Quick Recovery Sequence
Use this when both https://20.20.52.71/ and localhost are failing and you need to get the LAN site back online:

cd C:\laragon\www\attendancemonitoring
php artisan optimize:clear
npm run build
Then run this as Administrator:

cd C:\laragon\www\attendancemonitoring
.\tools\install-https-autostart.ps1 -IpAddress 20.20.52.71
Start Laragon Apache and MySQL, then open:

https://20.20.52.71/
If face recognition is required, start the face service:

cd C:\laragon\www\attendancemonitoring\face_service
.venv\Scripts\activate
uvicorn app.main:app --host 0.0.0.0 --port 8001 --ssl-keyfile C:/laragon/etc/ssl/laragon.key --ssl-certfile C:/laragon/etc/ssl/laragon.crt
Then check:

https://20.20.52.71:8001/health
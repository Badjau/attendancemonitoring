# ZKTeco Fingerprint Scanner Setup Guide

This guide explains how to set up the ZKTeco fingerprint scanner integration from start to finish.

The integration has two parts:

- Laravel web app: stores employees, fingerprint templates, fingerprint images, and attendance logs.
- C# ZKTeco Bridge: talks directly to the scanner SDK, displays the scanned fingerprint, enrolls templates, matches scans, and sends data to Laravel.

Browsers cannot directly access USB fingerprint scanners, so the C# bridge is required.

## 1. Requirements

Use the same Windows machine where the scanner is plugged in.

Required software:

- Laragon
- PHP and Composer
- Node.js and npm
- .NET SDK capable of building .NET Framework projects through `dotnet build`
- ZKTeco scanner driver
- ZKTeco Finger SDK files

Project path used by this guide:

```text
C:\laragon\www\attendancemonitoring
```

SDK path used by this guide:

```text
C:\Users\James-IT-Prog\Downloads\ZKFingerSDK 5.3_ZK10.0\ZKFingerSDK 5.3_Windows_ZK10.0
```

## 2. Install The ZKTeco Driver

Install the driver before running the bridge app.

Run:

```text
C:\Users\James-IT-Prog\Downloads\ZKFingerSDK 5.3_ZK10.0\ZKFingerSDK 5.3_Windows_ZK10.0\driver\5.3.0.26\setup.exe
```

After installation:

1. Plug in the ZKTeco fingerprint scanner.
2. Wait for Windows to finish device setup.
3. If the bridge later says no scanner is connected, unplug and reconnect the scanner.

## 3. Configure Laravel

Open PowerShell:

```powershell
cd C:\laragon\www\attendancemonitoring
```

Generate a scanner token:

```powershell
[Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }))
```

Copy the generated value.

Open `.env` and add:

```env
ZKTECO_SCANNER_TOKEN=PASTE_GENERATED_TOKEN_HERE
ZKTECO_BRIDGE_URL=http://127.0.0.1:8765
```

Clear Laravel config:

```powershell
php artisan config:clear
```

Run migrations:

```powershell
php artisan migrate
```

This creates the table:

```text
zkteco_fingerprint_templates
```

That table stores:

- employee ID
- ZKTeco fingerprint template
- enrolled fingerprint image
- scanner serial/name
- enrollment date

## 4. Configure The C# Bridge

Open:

```text
C:\laragon\www\attendancemonitoring\tools\ZKTecoBridge\App.config
```

Confirm these values:

```xml
<add key="ApiBaseUrl" value="http://attendancemonitoring.test/api/zkteco" />
<add key="ScannerToken" value="PASTE_GENERATED_TOKEN_HERE" />
<add key="DeviceSerial" value="ZKTECO-LOCAL" />
<add key="LocalBridgeUrl" value="http://127.0.0.1:8765/" />
```

Important:

- `ScannerToken` must exactly match `ZKTECO_SCANNER_TOKEN` in Laravel `.env`.
- `ApiBaseUrl` must point to your Laravel app.
- If you use `php artisan serve`, use:

```xml
<add key="ApiBaseUrl" value="http://127.0.0.1:8000/api/zkteco" />
```

If you use Laragon virtual host, use:

```xml
<add key="ApiBaseUrl" value="http://attendancemonitoring.test/api/zkteco" />
```

## 5. Build The Bridge App

Run:

```powershell
cd C:\laragon\www\attendancemonitoring
dotnet build .\tools\ZKTecoBridge\ZKTecoBridge.csproj -p:Configuration=Debug -p:Platform=x86
```

Expected output:

```text
Build succeeded.
```

The executable will be created here:

```text
C:\laragon\www\attendancemonitoring\tools\ZKTecoBridge\bin\x86\Debug\ZKTecoBridge.exe
```

The build may show warnings from `BitmapFormat.cs`. Those warnings are from the SDK sample helper and do not block the bridge.

## 6. Register Automatic Browser Launch

This lets the web app open the bridge automatically when you click the ZKTeco enrollment button.

Run this once:

```powershell
cd C:\laragon\www\attendancemonitoring
powershell -ExecutionPolicy Bypass -File .\tools\ZKTecoBridge\RegisterProtocol.ps1
```

This registers the custom Windows URL protocol:

```text
zkteco-bridge://
```

After this setup, the browser can ask Windows to open:

```text
zkteco-bridge://enroll
```

The browser may show a confirmation prompt. Click allow/open.

## 7. Start Laravel

If using Laragon virtual host, make sure Laragon is running and your site is accessible:

```text
http://attendancemonitoring.test
```

If using Laravel's built-in server:

```powershell
cd C:\laragon\www\attendancemonitoring
php artisan serve
```

Then use:

```text
http://127.0.0.1:8000
```

Make sure `ApiBaseUrl` in `App.config` matches the URL you are using.

## 8. Build Frontend Assets

Run:

```powershell
cd C:\laragon\www\attendancemonitoring
npm run build
```

This includes the JavaScript used by the employee fingerprint modal.

## 9. Test Laravel API

Use the same token from `.env`.

```powershell
$token = "PASTE_GENERATED_TOKEN_HERE"

Invoke-RestMethod `
  -Uri "http://attendancemonitoring.test/api/zkteco/employees" `
  -Headers @{ Authorization = "Bearer $token" }
```

Expected result:

```text
data
----
{...}
```

If using `php artisan serve`, use:

```powershell
Invoke-RestMethod `
  -Uri "http://127.0.0.1:8000/api/zkteco/employees" `
  -Headers @{ Authorization = "Bearer $token" }
```

Test fingerprint list:

```powershell
Invoke-RestMethod `
  -Uri "http://attendancemonitoring.test/api/zkteco/fingerprints" `
  -Headers @{ Authorization = "Bearer $token" }
```

It may return an empty `data` array before enrollment. That is normal.

## 10. Open The Bridge Manually For First Test

Before relying on automatic launch, test the bridge manually once.

```powershell
cd C:\laragon\www\attendancemonitoring
.\tools\ZKTecoBridge\bin\x86\Debug\ZKTecoBridge.exe
```

Expected behavior:

- The window opens.
- It initializes the scanner.
- It logs local bridge listening on:

```text
http://127.0.0.1:8765/
```

If no scanner is connected, the bridge will show an error. Install the driver and reconnect the scanner.

## 11. Enroll Fingerprint From Employee Registration

Go to the admin panel:

```text
http://attendancemonitoring.test/admin
```

Open:

```text
Employees > View or Edit Employee > Fingerprint
```

You will see two fingerprint areas:

- Browser fingerprint: WebAuthn/passkey fingerprint.
- ZKTeco scanner fingerprint: USB scanner enrollment.

For ZKTeco enrollment:

1. Open the employee.
2. Go to the fingerprint registration modal/step.
3. Click **Start ZKTeco enrollment**.
4. If the bridge is already open, it will receive the employee immediately.
5. If the bridge is closed, the browser will try to open `ZKTecoBridge.exe`.
6. Allow the browser prompt if shown.
7. Scan the same finger 3 times.
8. Wait for the bridge to show enrollment success.

After enrollment:

1. Refresh the employee page.
2. The fingerprint summary should show a ZKTeco scanner fingerprint.
3. The employee listing should show the enrolled fingerprint image in the `Fingerprint` column.

## 12. Test Attendance By Fingerprint

Open the bridge app.

Make sure it logs that fingerprint templates were synced.

Then scan an enrolled finger.

Expected behavior:

- The bridge displays the scanned fingerprint image.
- The bridge identifies the employee locally using the ZKTeco SDK.
- The bridge posts attendance to Laravel.
- Laravel creates an attendance record with:

```text
attendance_method = fingerprint
```

Check the attendance list in admin.

## 13. How The Data Flow Works

Enrollment:

```text
Admin employee page
  -> Start ZKTeco enrollment
  -> Browser calls local bridge
  -> Bridge activates selected employee
  -> Scanner captures finger 3 times
  -> SDK merges scans into one template
  -> Bridge sends template + image to Laravel
  -> Laravel stores it in zkteco_fingerprint_templates
```

Attendance:

```text
Scanner captures finger
  -> Bridge compares scan against synced templates
  -> Bridge finds matching employee
  -> Bridge sends attendance request to Laravel
  -> Laravel records attendance
```

## 14. Important Files

Laravel API:

```text
app\Http\Controllers\Api\ZktecoFingerprintController.php
routes\api.php
```

Fingerprint storage:

```text
app\Models\ZktecoFingerprintTemplate.php
database\migrations\2026_05_25_000001_create_zkteco_fingerprint_templates_table.php
```

Employee registration UI:

```text
resources\views\filament\admin\employees\fingerprint-enrollment.blade.php
resources\views\filament\admin\employees\fingerprint-summary.blade.php
resources\js\filament-fingerprint-enrollment.js
```

Employee listing fingerprint preview:

```text
app\Filament\Admin\Resources\Employees\Tables\EmployeesTable.php
```

C# bridge:

```text
tools\ZKTecoBridge\ZKTecoBridge.csproj
tools\ZKTecoBridge\MainForm.cs
tools\ZKTecoBridge\App.config
tools\ZKTecoBridge\RegisterProtocol.ps1
```

## 15. Troubleshooting

### Bridge says no scanner connected

Check:

- Driver is installed.
- Scanner is plugged in.
- Scanner appears in Windows Device Manager.
- No other app is using the scanner.

Reinstall driver if needed:

```text
C:\Users\James-IT-Prog\Downloads\ZKFingerSDK 5.3_ZK10.0\ZKFingerSDK 5.3_Windows_ZK10.0\driver\5.3.0.26\setup.exe
```

### Invalid scanner token

The token in Laravel `.env` and bridge `App.config` do not match.

Check:

```env
ZKTECO_SCANNER_TOKEN=...
```

And:

```xml
<add key="ScannerToken" value="..." />
```

Then run:

```powershell
php artisan config:clear
```

Restart the bridge.

### Browser button does not open the bridge

Run the protocol registration command again:

```powershell
cd C:\laragon\www\attendancemonitoring
powershell -ExecutionPolicy Bypass -File .\tools\ZKTecoBridge\RegisterProtocol.ps1
```

Then click **Start ZKTeco enrollment** again.

The browser may ask permission to open the app. Allow it.

### Bridge opens but does not connect to Laravel

Check `ApiBaseUrl` in:

```text
tools\ZKTecoBridge\App.config
```

If Laravel is on Laragon virtual host:

```xml
<add key="ApiBaseUrl" value="http://attendancemonitoring.test/api/zkteco" />
```

If Laravel is on `php artisan serve`:

```xml
<add key="ApiBaseUrl" value="http://127.0.0.1:8000/api/zkteco" />
```

Restart the bridge after changing `App.config`.

### Fingerprint enrollment crashes with `Value cannot be null. Parameter name: arr`

This was caused by an invalid stored template being loaded into the SDK. The bridge now skips invalid templates.

If it still happens:

1. Rebuild the bridge.
2. Restart the bridge.
3. Check the `zkteco_fingerprint_templates` table for rows with empty `template_base64`.

### Employee listing does not show fingerprint image

Only ZKTeco enrollments created through the bridge store `fingerprint_image_base64`.

Older rows may have templates without an image. Re-enroll the employee to store the preview image.

### Attendance fails because of location/geofence

The attendance service may require location if the employee has strict zone rules.

For fixed scanner terminals, either:

- configure a scanner location in the bridge/API payload, or
- adjust geofence rules for employees using the scanner.

## 16. Maintenance Notes

When changing frontend JavaScript:

```powershell
npm run build
```

When changing C# bridge code:

```powershell
dotnet build .\tools\ZKTecoBridge\ZKTecoBridge.csproj -p:Configuration=Debug -p:Platform=x86
```

When changing `.env`:

```powershell
php artisan config:clear
```

When adding/changing database tables:

```powershell
php artisan migrate
```

## 17. Quick Full Setup Checklist

1. Install ZKTeco driver.
2. Plug in scanner.
3. Add `ZKTECO_SCANNER_TOKEN` to Laravel `.env`.
4. Add same token to `tools\ZKTecoBridge\App.config`.
5. Confirm `ApiBaseUrl` in `App.config`.
6. Run `php artisan config:clear`.
7. Run `php artisan migrate`.
8. Run `npm run build`.
9. Build bridge with `dotnet build`.
10. Register protocol with `RegisterProtocol.ps1`.
11. Open admin employee fingerprint registration.
12. Click **Start ZKTeco enrollment**.
13. Scan the same finger 3 times.
14. Refresh employee listing and confirm fingerprint image appears.
15. Scan enrolled finger in bridge and confirm attendance record is created.

# ZKTeco Fingerprint Scanner Setup Guide

This guide explains the current ZKTeco fingerprint setup for the Laravel attendance app.

The integration has two parts:

- Laravel web app: stores employees, fingerprint templates, fingerprint images, and attendance logs.
- ZKTeco Local Agent: a Windows tray/background app that talks to the USB scanner, listens on `http://127.0.0.1:8765`, syncs templates from Laravel, and sends enrollment or attendance results back to Laravel.

Browsers cannot directly access USB fingerprint scanners. The browser talks to the local agent on the same Windows PC as the scanner.

The old `tools\ZKTecoBridge` project and `RegisterProtocol.ps1` flow are no longer used. Use `tools\ZktecoLocalAgent`.

## 1. Requirements

Use the Windows machine where the scanner is plugged in.

Required software:

- Laragon, if this PC also hosts the Laravel app
- PHP and Composer
- Node.js and npm
- .NET SDK 8
- ZKTeco scanner driver
- ZKTeco Finger SDK DLLs

Project path used by this guide:

```text
C:\laragon\www\attendancemonitoring
```

## 2. Install The ZKTeco Driver

Install the scanner driver before running the local agent.

After installation:

1. Plug in the ZKTeco fingerprint scanner.
2. Wait for Windows to finish device setup.
3. If the agent later says no scanner is connected, unplug and reconnect the scanner.

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

Open `.env` and set:

```env
ZKTECO_SCANNER_TOKEN=PASTE_GENERATED_TOKEN_HERE
ZKTECO_BRIDGE_URL=http://127.0.0.1:8765
ZKTECO_LOCAL_BRIDGE_URL=http://127.0.0.1:8765
```

Clear Laravel config and run migrations:

```powershell
php artisan config:clear
php artisan migrate
```

The fingerprint templates are stored in:

```text
zkteco_fingerprint_templates
```

## 4. Choose The Correct Laravel API URL

`ApiBaseUrl` is the Laravel URL the local agent calls. It must include `/api/zkteco`.

For Laragon HTTPS, use the HTTPS site URL:

```text
https://attendancemonitoring.test/api/zkteco
```

or, for LAN/server testing:

```text
https://SERVER_IP/api/zkteco
```

Only use this URL if you are actually running `php artisan serve` on port `8000`:

```text
http://127.0.0.1:8000/api/zkteco
```

Laragon being started does not mean port `8000` is listening. Laragon Apache normally serves the app on port `80` or `443`.

Keep the local agent listener as HTTP loopback:

```text
LocalListenUrl = http://127.0.0.1:8765
```

Do not change `LocalListenUrl` to HTTPS unless you also implement certificate binding for the local agent.

## 5. Copy The ZKTeco SDK DLLs

Copy these files into:

```text
tools\ZktecoLocalAgent\lib\x86
```

Required files:

- `libzkfpcsharp.dll`
- `libzkfp.dll`
- `ZKFPCap.dll`

The agent is published as a self-contained `win-x86` app because the ZKTeco SDK DLLs are x86.

## 6. Publish The Local Agent

Run:

```powershell
cd C:\laragon\www\attendancemonitoring

dotnet publish .\tools\ZktecoLocalAgent\ZktecoLocalAgent.csproj -c Release -r win-x86 --self-contained true -o .\tools\ZktecoLocalAgent\publish
```

Important: `dotnet build` is only a compile check. The installer copies from:

```text
tools\ZktecoLocalAgent\publish
```

Always run `dotnet publish` before reinstalling the agent.

If publish fails because stale generated output is locked or corrupted, close the agent and remove generated folders:

```bat
rmdir /s /q tools\ZktecoLocalAgent\bin
rmdir /s /q tools\ZktecoLocalAgent\obj
```

Then run `dotnet publish` again.

## 7. Install The Local Agent

Run the installer after publishing:

```powershell
cd C:\laragon\www\attendancemonitoring

.\tools\ZktecoLocalAgent\Installer\install-agent.ps1 `
  -ApiBaseUrl "https://attendancemonitoring.test/api/zkteco" `
  -ScannerToken "PASTE_GENERATED_TOKEN_HERE" `
  -DeviceSerial "ZKTECO-LOCAL"
```

For LAN/server testing, replace `ApiBaseUrl` with:

```text
https://SERVER_IP/api/zkteco
```

The installer:

- Stops any running `ZktecoLocalAgent.exe`.
- Copies published files to `%LOCALAPPDATA%\ZktecoLocalAgent`.
- Writes `%LOCALAPPDATA%\ZktecoLocalAgent\appsettings.json`.
- Registers the agent to start on Windows logon.
- Starts the agent.

The installed `appsettings.json` is the runtime config. Editing `tools\ZktecoLocalAgent\appsettings.json` does not change the already installed agent.

## 8. Verify The Agent

Open this on the scanner PC:

```text
http://127.0.0.1:8765/health
```

Expected when the scanner and SDK are ready:

- `ok: true`
- `scanner_available: true`
- `sdk_available: true`

Check current state:

```text
http://127.0.0.1:8765/status
```

Check logs:

```text
%LOCALAPPDATA%\ZktecoLocalAgent\logs
```

The tray menu also has `Status`, `Restart`, `Open log folder`, and `Exit`.

## 9. Test The Laravel API

Use the same token from `.env`.

```powershell
$token = "PASTE_GENERATED_TOKEN_HERE"

Invoke-RestMethod `
  -Uri "https://attendancemonitoring.test/api/zkteco/fingerprints/manifest" `
  -Headers @{ Authorization = "Bearer $token" }
```

For LAN/server testing:

```powershell
Invoke-RestMethod `
  -Uri "https://SERVER_IP/api/zkteco/fingerprints/manifest" `
  -Headers @{ Authorization = "Bearer $token" }
```

If this fails from the scanner PC, fix Laravel HTTPS/network access before troubleshooting the scanner.

## 10. Test Enrollment

Use the browser on the same PC as the scanner.

1. Confirm `http://127.0.0.1:8765/health` responds.
2. Open the Laravel admin panel.
3. Open the employee fingerprint registration flow.
4. Start ZKTeco enrollment.
5. Scan the same finger 3 times.
6. Submit/save the enrollment when the browser prompts.
7. Refresh the employee page and confirm the ZKTeco fingerprint summary is shown.

The browser should call:

```text
http://127.0.0.1:8765/commands/...
```

No custom URL protocol registration is required.

## 11. Test Attendance

1. Confirm the agent is running.
2. Open the attendance/timeclock page in the browser on the scanner PC.
3. Start the fingerprint attendance action.
4. Scan an enrolled finger.
5. Complete the browser flow.
6. Confirm Laravel creates an attendance record with:

```text
attendance_method = fingerprint
```

## 12. Data Flow

Enrollment:

```text
Admin employee page
  -> Browser calls local agent at 127.0.0.1:8765
  -> Agent captures the same finger 3 times
  -> SDK merges scans into one template
  -> Browser commits enrollment
  -> Agent sends template + image to Laravel
  -> Laravel stores it in zkteco_fingerprint_templates
```

Attendance:

```text
Timeclock page
  -> Browser starts a local agent attendance command
  -> Agent compares scan against synced templates
  -> Browser completes photo/facial verification when required
  -> Agent sends attendance request to Laravel
  -> Laravel records attendance
```

## 13. Important Files

Laravel API:

```text
app\Http\Controllers\Api\ZktecoFingerprintController.php
routes\api.php
```

Browser integration:

```text
resources\js\filament-fingerprint-enrollment.js
```

Local agent source:

```text
tools\ZktecoLocalAgent\ZktecoLocalAgent.csproj
tools\ZktecoLocalAgent\Program.cs
tools\ZktecoLocalAgent\Services\LaravelApiClient.cs
tools\ZktecoLocalAgent\Services\TemplateSyncService.cs
tools\ZktecoLocalAgent\Installer\install-agent.ps1
```

Installed agent:

```text
%LOCALAPPDATA%\ZktecoLocalAgent
```

## 14. Troubleshooting

### Fingerprint sync failed: connection refused on 127.0.0.1:8000

The installed agent is pointing to Laravel's built-in dev server URL, but `php artisan serve` is not running.

Open:

```text
%LOCALAPPDATA%\ZktecoLocalAgent\appsettings.json
```

Set `ApiBaseUrl` to the real Laravel URL, for example:

```json
"ApiBaseUrl": "https://attendancemonitoring.test/api/zkteco"
```

Keep:

```json
"LocalListenUrl": "http://127.0.0.1:8765"
```

Restart the agent after editing the installed config.

### install-https-autostart was run but the agent still uses 127.0.0.1:8000

`tools\install-https-autostart.cmd` configures Apache HTTPS, firewall rules, the Laravel `APP_URL`, and the Apache watchdog scheduled task. It does not install or reconfigure the fingerprint agent.

Run `dotnet publish`, then `tools\ZktecoLocalAgent\Installer\install-agent.ps1` with the correct `ApiBaseUrl`.

### Invalid scanner token

The token in Laravel `.env` and the installed agent config do not match.

Check:

```env
ZKTECO_SCANNER_TOKEN=...
```

and:

```text
%LOCALAPPDATA%\ZktecoLocalAgent\appsettings.json
```

Then run:

```powershell
php artisan config:clear
```

Restart the agent.

### Agent says no scanner connected

Check:

- Driver is installed.
- Scanner is plugged in.
- Scanner appears in Windows Device Manager.
- No other app is using the scanner.
- The required ZKTeco SDK DLLs exist in the published and installed agent folder.

### Browser cannot connect to 127.0.0.1:8765

Check:

- `ZktecoLocalAgent.exe` is running.
- `http://127.0.0.1:8765/health` responds on the scanner PC.
- The browser is running on the same PC as the USB scanner.

### Fingerprint templates do not refresh after connection loss or Laravel restart

The current local agent retries sync automatically and can try configured fallback URLs. Confirm the installed agent was republished/reinstalled after agent code changes.

Check:

```text
%LOCALAPPDATA%\ZktecoLocalAgent\appsettings.json
%LOCALAPPDATA%\ZktecoLocalAgent\logs
```

## 15. Maintenance

When changing frontend JavaScript:

```powershell
npm run build
```

When changing C# local agent code:

```powershell
dotnet publish .\tools\ZktecoLocalAgent\ZktecoLocalAgent.csproj -c Release -r win-x86 --self-contained true -o .\tools\ZktecoLocalAgent\publish

.\tools\ZktecoLocalAgent\Installer\install-agent.ps1 `
  -ApiBaseUrl "https://attendancemonitoring.test/api/zkteco" `
  -ScannerToken "PASTE_GENERATED_TOKEN_HERE" `
  -DeviceSerial "ZKTECO-LOCAL"
```

When changing `.env`:

```powershell
php artisan config:clear
```

When adding/changing database tables:

```powershell
php artisan migrate
```

## 16. Quick Setup Checklist

1. Install the ZKTeco driver.
2. Plug in the scanner.
3. Add `ZKTECO_SCANNER_TOKEN` to Laravel `.env`.
4. Set `ZKTECO_BRIDGE_URL` and `ZKTECO_LOCAL_BRIDGE_URL` to `http://127.0.0.1:8765`.
5. Run `php artisan config:clear`.
6. Run `php artisan migrate`.
7. Copy the ZKTeco SDK DLLs into `tools\ZktecoLocalAgent\lib\x86`.
8. Run `dotnet publish` for `tools\ZktecoLocalAgent\ZktecoLocalAgent.csproj`.
9. Run `tools\ZktecoLocalAgent\Installer\install-agent.ps1` with the correct HTTPS `ApiBaseUrl`.
10. Open `http://127.0.0.1:8765/health`.
11. Run `npm run build` if frontend assets changed.
12. Test ZKTeco enrollment from the employee fingerprint page.
13. Test fingerprint attendance from the scanner PC.

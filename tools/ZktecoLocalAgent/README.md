# ZKTeco Local Fingerprint Agent

Windows tray/background agent for scanner PCs. It exposes a local HTTP API on `http://127.0.0.1:8765`, syncs fingerprint templates from Laravel into a local SQLite cache, loads them into the ZKTeco SDK matcher, and handles browser-driven fingerprint enrollment, attendance, and unlock flows.

## What This Agent Does

- Runs locally on the same Windows PC as the ZKTeco scanner
- Exposes a local API that the website can call from the browser
- Syncs fingerprint templates from Laravel
- Caches templates in SQLite
- Loads templates into the ZKTeco SDK matcher
- Sends enrollment and attendance results back to Laravel

## Requirements

Before building or running the agent, make sure the scanner PC has:

- Windows
- A supported ZKTeco fingerprint scanner
- The ZKTeco SDK DLLs

The agent is published as a self-contained `win-x86` app, so the scanner PC does not need a separate .NET runtime installation. It still runs as 32-bit because the ZKTeco SDK DLLs are x86.

## Required ZKTeco SDK DLLs

Copy these files into `tools\ZktecoLocalAgent\lib\x86` before publishing:

- `libzkfpcsharp.dll`
- `libzkfp.dll`
- `ZKFPCap.dll`

Expected source folder:

```text
tools\ZktecoLocalAgent\lib\x86
```

After publishing, the output should contain:

- `publish\libzkfpcsharp.dll`
- `publish\lib\x86\libzkfp.dll`
- `publish\lib\x86\ZKFPCap.dll`

## Configuration Values

The installer writes these values into `appsettings.json` for the installed agent:

- `ApiBaseUrl`
  The Laravel base endpoint for ZKTeco API calls. This should be your Laravel app URL plus `/api/zkteco`.

  Examples:
  - `http://attendancemonitoring.test/api/zkteco`
  - `http://127.0.0.1:8000/api/zkteco`
  - `https://your-domain/api/zkteco`

- `ScannerToken`
  Bearer token used by the agent when calling Laravel. This must match `ZKTECO_SCANNER_TOKEN` in Laravel `.env`.

- `DeviceSerial`
  Unique identifier for the scanner PC. This is sent back to Laravel as `device_serial`. Pick a stable name such as `SCANNER-PC-01`.

- `LocalListenUrl`
  Local address the agent listens on. Default:
  - `http://127.0.0.1:8765`

## Build And Publish

Build order matters:

1. Copy the ZKTeco DLLs into `tools\ZktecoLocalAgent\lib\x86`
2. Publish the project

Publish command:

```powershell
dotnet publish .\tools\ZktecoLocalAgent\ZktecoLocalAgent.csproj -c Release -r win-x86 --self-contained true -o .\tools\ZktecoLocalAgent\publish
```

## Install On The Scanner PC

Run the installer after publishing:

```powershell
.\tools\ZktecoLocalAgent\Installer\install-agent.ps1 `
  -ApiBaseUrl "https://YOUR-APP-URL/api/zkteco" `
  -ScannerToken "fhDVzlVcoUqRxpVtWsr8N5YEPXzsgoNJb4GsGymgfTM" `
  -DeviceSerial "SCANNER-PC-01"
```

What the installer does:

- Copies the published files to `%LOCALAPPDATA%\ZktecoLocalAgent`
- Writes `appsettings.json`
- Registers the app to start on Windows logon
- Starts the agent

Default install location:

```text
%LOCALAPPDATA%\ZktecoLocalAgent
```

## Verify The Agent Is Running

After installation, verify the agent locally on the scanner PC.

### 1. Check The Health Endpoint

Open this in a browser:

```text
http://127.0.0.1:8765/health
```

If the agent is running, you should get a JSON response.

### 2. Check Current Status

Open:

```text
http://127.0.0.1:8765/status
```

This shows the current command state.

### 3. Confirm The Process Is Running

You should see:

- `ZktecoLocalAgent.exe` running
- a tray icon in Windows notification area

### 4. Check Logs

Log folder:

```text
%LOCALAPPDATA%\ZktecoLocalAgent\logs
```

The tray menu also includes:

- `Status`
- `Restart`
- `Open log folder`
- `Exit`

## Local API Endpoints

The agent exposes these local endpoints:

- `GET /health`
- `GET /status`
- `GET /events`
- `POST /commands/enroll`
- `POST /commands/attendance`
- `POST /commands/unlock`
- `POST /commands/{commandId}/commit-enrollment`
- `POST /commands/{commandId}/finalize-attendance`
- `POST /commands/{commandId}/cancel`

Compatibility aliases also exist for older flows:

- `POST /enroll`
- `POST /attendance`
- `POST /unlock`
- `POST /commit-enrollment`
- `POST /finalize-attendance`

## How To Test With The Website

Use the website on the same PC where the scanner and agent are running.

### Enrollment Flow

1. Connect the scanner
2. Start the agent
3. Confirm `http://127.0.0.1:8765/health` responds
4. Open the website in the browser
5. Go to the fingerprint enrollment page
6. Start an enrollment action from the UI
7. When prompted, place the required finger on the scanner
8. Complete the enrollment flow in the browser

### Attendance Flow

1. Confirm the agent is running
2. Open the website attendance page
3. Trigger the fingerprint attendance action
4. Place a finger on the scanner
5. Wait for the browser flow to receive the local scan result
6. Confirm the Laravel app records the attendance result

### Browser Verification

Open browser DevTools and check the `Network` tab. During fingerprint actions, you should see requests to:

```text
http://127.0.0.1:8765/commands/...
```

If the browser never calls the local agent, the website-side integration is the problem. If it does call the local agent but fails, inspect the agent logs and `/health`.

## Manual Run

To run the installed agent manually:

```powershell
$env:LOCALAPPDATA\ZktecoLocalAgent\ZktecoLocalAgent.exe
```

If it exits immediately, check:

- ZKTeco DLL presence
- log files

## Troubleshooting

### `libzkfpcsharp.dll` missing

Make sure:

- `libzkfpcsharp.dll` exists in `tools\ZktecoLocalAgent\lib\x86` before publish
- the published output contains `publish\libzkfpcsharp.dll`

### Agent installs but does not start

Common causes:

- missing ZKTeco SDK DLLs
- scanner SDK dependency issue

Check:

- `%LOCALAPPDATA%\ZktecoLocalAgent\logs`
- `http://127.0.0.1:8765/health`

### Browser cannot connect to `127.0.0.1:8765`

Make sure:

- the agent is running on the same PC as the browser
- the health endpoint responds
- the website is being opened on the scanner PC, not on another workstation

### Laravel rejects requests

Check:

- `ApiBaseUrl` points to the correct Laravel server
- `ScannerToken` exactly matches `ZKTECO_SCANNER_TOKEN` in Laravel `.env`
- the server route `/api/zkteco/...` is reachable from the scanner PC

## Files Worth Knowing

- `tools\ZktecoLocalAgent\Installer\install-agent.ps1`
- `tools\ZktecoLocalAgent\appsettings.json`
- `tools\ZktecoLocalAgent\lib\x86\`
- `tools\ZktecoLocalAgent\publish\`

Installed location:

- `%LOCALAPPDATA%\ZktecoLocalAgent`

## Quick Start

If you only need the short version:

1. Put the 3 ZKTeco DLLs into `tools\ZktecoLocalAgent\lib\x86`
2. Run `dotnet publish`
3. Run `install-agent.ps1` with the correct `ApiBaseUrl`, `ScannerToken`, and `DeviceSerial`
4. Open `http://127.0.0.1:8765/health`
5. Test enrollment or attendance from the website on the same scanner PC

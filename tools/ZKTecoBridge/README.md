# ZKTeco Bridge

This WinForms bridge connects the ZKTeco ZKFinger SDK to the Laravel attendance API.

## Setup

1. Install the ZKTeco reader driver:
   `C:\Users\James-IT-Prog\Downloads\ZKFingerSDK 5.3_ZK10.0\ZKFingerSDK 5.3_Windows_ZK10.0\driver\5.3.0.26\setup.exe`
2. Set `ZKTECO_SCANNER_TOKEN` in Laravel `.env`.
3. Put the same token in `tools/ZKTecoBridge/App.config` under `ScannerToken`.
4. Confirm `ApiBaseUrl` points to the local Laravel app, for example:
   `http://attendancemonitoring.test/api/zkteco`
5. Build `ZKTecoBridge.csproj` as `x86`.
6. Register the browser launcher protocol once:
   `powershell -ExecutionPolicy Bypass -File tools\ZKTecoBridge\RegisterProtocol.ps1`

## Auto-start after power loss

Run this once on the scanner PC as Administrator:

```powershell
cd C:\laragon\www\attendancemonitoring
.\tools\ZKTecoBridge\InstallAutostart.ps1 `
  -ApiBaseUrl "http://20.20.14.119/api/zkteco" `
  -ScannerToken "PASTE_THE_ZKTECO_SCANNER_TOKEN_HERE" `
  -DeviceSerial "ZKTECO-SCANNER-PC"
```

Or right-click `tools\ZKTecoBridge\InstallAutostart.cmd` and choose **Run as administrator**.

This creates a Windows Scheduled Task named `ZKTecoBridgeWatchdog`. When that Windows user logs in, the watchdog starts the bridge and reopens it automatically if it closes.

Important: because the bridge is a desktop/WinForms app, Windows still needs to sign in to the scanner user after a power outage. If the PC stops at the Windows login screen, the bridge will not show until someone logs in or Windows auto-login is configured for that scanner account.

The bridge loads enrolled templates from Laravel, uses the SDK for local matching, and posts the matched employee attendance back to Laravel.

When the bridge is open, the employee fingerprint modal in the admin panel can start enrollment through the local bridge URL:
`http://127.0.0.1:8765/enroll`

If the bridge is closed, the modal can launch it through:
`zkteco-bridge://enroll`

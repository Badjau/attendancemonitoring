# ZKTeco Bridge

This WinForms bridge connects the ZKTeco ZKFinger SDK to the Laravel attendance API.

## Setup

1. Install the ZKTeco reader driver:
   `C:\Users\James-IT-Prog\Downloads\ZKFingerSDK 5.3_ZK10.0\ZKFingerSDK 5.3_Windows_ZK10.0\driver\5.3.0.26\setup.exe`
2. Set `ZKTECO_SCANNER_TOKEN` in Laravel `.env`.
3. Put the same token in `tools/ZKTecoBridge/App.config` under `ScannerToken`.
4. Confirm `ApiBaseUrl` points to the local Laravel app, for example:
   `http://timeclock-system.test/api/zkteco`
5. Build `ZKTecoBridge.csproj` as `x86`.
6. Register the browser launcher protocol once:
   `powershell -ExecutionPolicy Bypass -File tools\ZKTecoBridge\RegisterProtocol.ps1`

The bridge loads enrolled templates from Laravel, uses the SDK for local matching, and posts the matched employee attendance back to Laravel.

When the bridge is open, the employee fingerprint modal in the admin panel can start enrollment through the local bridge URL:
`http://127.0.0.1:8765/enroll`

If the bridge is closed, the modal can launch it through:
`zkteco-bridge://enroll`

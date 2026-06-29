@echo off
setlocal

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0InstallAutostart.ps1" %*

if errorlevel 1 (
    echo.
    echo Installation failed. Run this file as Administrator and check the error above.
    pause
    exit /b 1
)

echo.
echo ZKTeco Bridge autostart setup finished.
pause

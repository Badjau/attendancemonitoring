@echo off
setlocal

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-https-autostart.ps1" %*

if errorlevel 1 (
    echo.
    echo Installation failed. Run this file as Administrator and check the error above.
    pause
    exit /b 1
)

echo.
echo HTTPS auto-start setup finished.
pause

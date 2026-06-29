# HTTPS Auto-Start Install

Run this on the PC where the attendance web app is installed.

## Requirements

- Laragon installed, normally at `C:\laragon`
- Project copied to `C:\laragon\www\attendancemonitoring`
- Run the installer as Administrator

## Install

Right-click this file and choose **Run as administrator**:

```text
tools\install-https-autostart.cmd
```

Or run PowerShell as Administrator:

```powershell
cd C:\laragon\www\attendancemonitoring
.\tools\install-https-autostart.ps1
```

If the PC uses a fixed IP, pass it explicitly:

```powershell
.\tools\install-https-autostart.ps1 -IpAddress 20.20.14.119
```

## What It Does

- Detects the PC LAN IP address
- Creates a local SSL certificate for that IP
- Creates the Apache HTTP and HTTPS virtual host
- Updates `.env` `APP_URL=https://<ip>`
- Clears Laravel caches
- Opens Windows firewall ports `80` and `443`
- Registers a Windows Scheduled Task named `AttendanceMonitoringStartHttps`
- Starts an Apache watchdog immediately, which reopens Apache if it closes

After install, open:

```text
https://<pc-ip>
```

The browser may show a certificate warning because the certificate is local/self-signed.

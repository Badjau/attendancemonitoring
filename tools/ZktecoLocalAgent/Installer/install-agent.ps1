param(
    [string]$InstallDir = "$env:LOCALAPPDATA\ZktecoLocalAgent",
    [string]$ApiBaseUrl = "http://attendancemonitoring.test/api/zkteco",
    [string]$ScannerToken = "",
    [string]$LocalListenUrl = "http://127.0.0.1:8765",
    [string]$DeviceSerial = "ZKTECO-LOCAL",
    [int]$SyncIntervalSeconds = 60,
    [int]$LogRetentionDays = 14,
    [bool]$AllowInvalidServerCertificate = $true
)

$ErrorActionPreference = "Stop"
$source = Split-Path -Parent $PSScriptRoot

New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
Copy-Item -Path (Join-Path $source "publish\*") -Destination $InstallDir -Recurse -Force

$config = @{
    ZktecoAgent = @{
        ApiBaseUrl = $ApiBaseUrl
        ScannerToken = $ScannerToken
        LocalListenUrl = $LocalListenUrl
        DeviceSerial = $DeviceSerial
        SyncIntervalSeconds = $SyncIntervalSeconds
        LogRetentionDays = $LogRetentionDays
        AllowInvalidServerCertificate = $AllowInvalidServerCertificate
    }
} | ConvertTo-Json -Depth 4

Set-Content -Path (Join-Path $InstallDir "appsettings.json") -Value $config -Encoding UTF8

$exe = Join-Path $InstallDir "ZktecoLocalAgent.exe"
$runKey = "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run"
New-ItemProperty -Path $runKey -Name "ZktecoLocalAgent" -Value "`"$exe`"" -PropertyType String -Force | Out-Null

if ($LocalListenUrl -notmatch "127\.0\.0\.1|localhost") {
    netsh advfirewall firewall add rule name="Zkteco Local Fingerprint Agent" dir=in action=allow program="$exe" enable=yes | Out-Null
}

Start-Process -FilePath $exe -WindowStyle Hidden
Write-Host "ZKTeco local fingerprint agent installed to $InstallDir"

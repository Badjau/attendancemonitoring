param(
    [string] $ExePath = "$PSScriptRoot\bin\x86\Debug\ZKTecoBridge.exe",
    [string] $ApiBaseUrl = '',
    [string] $ScannerToken = '',
    [string] $DeviceSerial = '',
    [string] $LocalBridgeUrl = 'http://0.0.0.0:8765/',
    [int] $CheckSeconds = 30
)

$ErrorActionPreference = 'Stop'

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)

    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run this script from an elevated PowerShell window: right-click PowerShell, then choose Run as administrator.'
    }
}

function Set-AppSetting {
    param(
        [xml] $Config,
        [string] $Key,
        [string] $Value
    )

    if ($Value -eq '') {
        return
    }

    $node = $Config.configuration.appSettings.add | Where-Object { $_.key -eq $Key } | Select-Object -First 1

    if ($node) {
        $node.value = $Value
        return
    }

    $newNode = $Config.CreateElement('add')
    $newNode.SetAttribute('key', $Key)
    $newNode.SetAttribute('value', $Value)
    [void] $Config.configuration.appSettings.AppendChild($newNode)
}

Assert-Administrator

$resolvedExe = (Resolve-Path -LiteralPath $ExePath).Path
$bridgeDir = Split-Path -Parent $resolvedExe
$configPath = "$resolvedExe.config"
$watchdogPath = Join-Path $PSScriptRoot 'StartBridgeWatchdog.ps1'

if (-not (Test-Path -LiteralPath $watchdogPath)) {
    throw "Watchdog script was not found: $watchdogPath"
}

if (Test-Path -LiteralPath $configPath) {
    [xml] $config = Get-Content -LiteralPath $configPath
    Set-AppSetting -Config $config -Key 'ApiBaseUrl' -Value $ApiBaseUrl
    Set-AppSetting -Config $config -Key 'ScannerToken' -Value $ScannerToken
    Set-AppSetting -Config $config -Key 'DeviceSerial' -Value $DeviceSerial
    Set-AppSetting -Config $config -Key 'LocalBridgeUrl' -Value $LocalBridgeUrl
    $config.Save($configPath)
}

if (-not (Get-NetFirewallRule -DisplayName 'ZKTeco Bridge Local API 8765' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule `
        -DisplayName 'ZKTeco Bridge Local API 8765' `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort 8765 | Out-Null
}

$taskName = 'ZKTecoBridgeWatchdog'
$taskAction = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$watchdogPath`" -ExePath `"$resolvedExe`" -CheckSeconds $CheckSeconds" `
    -WorkingDirectory $bridgeDir
$taskTrigger = New-ScheduledTaskTrigger -AtLogOn
$taskPrincipal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest
$taskSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $taskAction `
    -Trigger $taskTrigger `
    -Principal $taskPrincipal `
    -Settings $taskSettings `
    -Force | Out-Null

Start-Process `
    -FilePath 'powershell.exe' `
    -ArgumentList @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-WindowStyle', 'Hidden',
        '-File', "`"$watchdogPath`"",
        '-ExePath', "`"$resolvedExe`"",
        '-CheckSeconds', $CheckSeconds
    ) `
    -WindowStyle Hidden

Write-Host "ZKTeco Bridge autostart is installed."
Write-Host "Scheduled task: $taskName"
Write-Host "Bridge executable: $resolvedExe"
Write-Host "The bridge will start after this Windows user logs in and will reopen if it closes."

param(
    [string] $ExePath = "$PSScriptRoot\bin\x86\Debug\ZKTecoBridge.exe"
)

$resolvedExe = (Resolve-Path -LiteralPath $ExePath).Path
$protocolRoot = 'HKCU:\Software\Classes\zkteco-bridge'

New-Item -Path $protocolRoot -Force | Out-Null
New-ItemProperty -Path $protocolRoot -Name '(default)' -Value 'URL:ZKTeco Bridge Protocol' -PropertyType String -Force | Out-Null
New-ItemProperty -Path $protocolRoot -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null

New-Item -Path "$protocolRoot\shell\open\command" -Force | Out-Null
Set-ItemProperty -Path "$protocolRoot\shell\open\command" -Name '(default)' -Value "`"$resolvedExe`" `"%1`""

Write-Host "Registered zkteco-bridge:// protocol for $resolvedExe"

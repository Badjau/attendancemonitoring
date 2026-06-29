param(
    [string] $ExePath = "$PSScriptRoot\bin\x86\Debug\ZKTecoBridge.exe",
    [int] $CheckSeconds = 30
)

$ErrorActionPreference = 'Stop'

$resolvedExe = (Resolve-Path -LiteralPath $ExePath).Path
$processName = [System.IO.Path]::GetFileNameWithoutExtension($resolvedExe)
$hashBytes = [System.Security.Cryptography.SHA256]::Create().ComputeHash([System.Text.Encoding]::UTF8.GetBytes($resolvedExe))
$hash = [System.BitConverter]::ToString($hashBytes).Replace('-', '')
$mutex = [System.Threading.Mutex]::new($false, "Local\ZKTecoBridgeWatchdog-$hash")

if (-not $mutex.WaitOne(0)) {
    return
}

try {
    while ($true) {
        $running = Get-Process -Name $processName -ErrorAction SilentlyContinue |
            Where-Object { $_.Path -eq $resolvedExe -or -not $_.Path }

        if (-not $running) {
            Start-Process -FilePath $resolvedExe -WindowStyle Minimized
        }

        Start-Sleep -Seconds $CheckSeconds
    }
} finally {
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}

param(
    [string] $LaragonRoot = 'C:\laragon',
    [int] $CheckSeconds = 30
)

$ErrorActionPreference = 'Stop'

function Find-FirstFile {
    param(
        [string] $Root,
        [string] $Filter
    )

    Get-ChildItem -Path $Root -Recurse -Filter $Filter -File -ErrorAction SilentlyContinue |
        Sort-Object FullName |
        Select-Object -First 1
}

$apache = Find-FirstFile -Root (Join-Path $LaragonRoot 'bin\apache') -Filter 'httpd.exe'

if (-not $apache) {
    throw "Apache httpd.exe was not found under $LaragonRoot\bin\apache."
}

$hashBytes = [System.Security.Cryptography.SHA256]::Create().ComputeHash([System.Text.Encoding]::UTF8.GetBytes($apache.FullName))
$hash = [System.BitConverter]::ToString($hashBytes).Replace('-', '')
$mutex = [System.Threading.Mutex]::new($false, "Local\AttendanceMonitoringHttpsWatchdog-$hash")

if (-not $mutex.WaitOne(0)) {
    return
}

try {
    while ($true) {
        $running = Get-Process -Name httpd -ErrorAction SilentlyContinue |
            Where-Object { $_.Path -eq $apache.FullName -or -not $_.Path }

        if (-not $running) {
            Start-Process -FilePath $apache.FullName -WindowStyle Hidden
        }

        Start-Sleep -Seconds $CheckSeconds
    }
} finally {
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}

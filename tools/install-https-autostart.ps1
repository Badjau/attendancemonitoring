param(
    [string] $LaragonRoot = 'C:\laragon',
    [string] $ProjectPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
    [string] $SiteName = 'attendancemonitoring',
    [string] $Domain = 'attendancemonitoring.test',
    [string] $IpAddress = '',
    [switch] $SkipFirewall
)

$ErrorActionPreference = 'Stop'

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)

    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run this script from an elevated PowerShell window: right-click PowerShell, then choose Run as administrator.'
    }
}

function Find-FirstFile {
    param(
        [string] $Root,
        [string] $Filter
    )

    Get-ChildItem -Path $Root -Recurse -Filter $Filter -File -ErrorAction SilentlyContinue |
        Sort-Object FullName |
        Select-Object -First 1
}

function Get-PrimaryIPv4 {
    $address = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object {
            $_.IPAddress -notlike '127.*' -and
            $_.IPAddress -notlike '169.254.*' -and
            $_.PrefixOrigin -ne 'WellKnown'
        } |
        Sort-Object InterfaceMetric, InterfaceIndex |
        Select-Object -First 1

    if (-not $address) {
        throw 'Could not detect a LAN IPv4 address. Re-run with -IpAddress 20.20.x.x.'
    }

    $address.IPAddress
}

function Set-EnvValue {
    param(
        [string] $Path,
        [string] $Key,
        [string] $Value
    )

    $lines = @()

    if (Test-Path -LiteralPath $Path) {
        $lines = Get-Content -LiteralPath $Path
    }

    $escaped = [regex]::Escape($Key)
    $replacement = "$Key=$Value"
    $found = $false

    $lines = $lines | ForEach-Object {
        if ($_ -match "^$escaped=") {
            $found = $true
            $replacement
        } else {
            $_
        }
    }

    if (-not $found) {
        $lines += $replacement
    }

    Set-Content -LiteralPath $Path -Value $lines -Encoding ASCII
}

Assert-Administrator

$ProjectPath = (Resolve-Path $ProjectPath).Path
$publicPath = Join-Path $ProjectPath 'public'
$envPath = Join-Path $ProjectPath '.env'

if (-not (Test-Path -LiteralPath $publicPath)) {
    throw "Laravel public directory was not found: $publicPath"
}

if ($IpAddress -eq '') {
    $IpAddress = Get-PrimaryIPv4
}

$apache = Find-FirstFile -Root (Join-Path $LaragonRoot 'bin\apache') -Filter 'httpd.exe'
$openssl = Find-FirstFile -Root (Join-Path $LaragonRoot 'bin\apache') -Filter 'openssl.exe'
$php = Find-FirstFile -Root (Join-Path $LaragonRoot 'bin\php') -Filter 'php.exe'

if (-not $apache) {
    throw "Apache httpd.exe was not found under $LaragonRoot\bin\apache."
}

if (-not $openssl) {
    throw "OpenSSL was not found under $LaragonRoot\bin\apache."
}

$apacheRoot = Split-Path (Split-Path $apache.FullName -Parent) -Parent
$httpdConf = Join-Path $apacheRoot 'conf\httpd.conf'
$laragonApacheEtc = Join-Path $LaragonRoot 'etc\apache2'
$sitesEnabled = Join-Path $laragonApacheEtc 'sites-enabled'
$sslDir = Join-Path $LaragonRoot 'etc\ssl'
$sslConf = Join-Path $laragonApacheEtc 'httpd-ssl.conf'
$certPath = Join-Path $sslDir "$SiteName.crt"
$keyPath = Join-Path $sslDir "$SiteName.key"
$certConfigPath = Join-Path $sslDir "$SiteName.cnf"
$vhostPath = Join-Path $sitesEnabled "auto.$Domain.conf"

New-Item -ItemType Directory -Force -Path $laragonApacheEtc, $sitesEnabled, $sslDir | Out-Null

$certConfig = @"
[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
x509_extensions = v3_req

[dn]
CN = $IpAddress

[v3_req]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
IP.1 = $IpAddress
DNS.1 = $Domain
DNS.2 = *.$Domain
"@

Set-Content -LiteralPath $certConfigPath -Value $certConfig -Encoding ASCII

& $openssl.FullName req -x509 -nodes -days 825 -newkey rsa:2048 `
    -keyout $keyPath `
    -out $certPath `
    -config $certConfigPath | Out-Null

$sslBaseConf = @'
Listen 443

SSLCipherSuite HIGH:!aNULL:!MD5:!RC4:!3DES:!CAMELLIA
SSLProxyCipherSuite HIGH:!aNULL:!MD5:!RC4:!3DES:!CAMELLIA
SSLHonorCipherOrder on

SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
SSLProxyProtocol all -SSLv3 -TLSv1 -TLSv1.1

SSLSessionCache "shmcb:logs/ssl_scache(512000)"
SSLSessionCacheTimeout 300
'@

Set-Content -LiteralPath $sslConf -Value $sslBaseConf -Encoding ASCII

$publicApachePath = $publicPath.Replace('\', '/')
$certApachePath = $certPath.Replace('\', '/')
$keyApachePath = $keyPath.Replace('\', '/')

$vhost = @"
<VirtualHost *:80>
    DocumentRoot "$publicApachePath"
    ServerName $Domain
    ServerAlias *.$Domain $IpAddress
    <Directory "$publicApachePath">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

<VirtualHost *:443>
    DocumentRoot "$publicApachePath"
    ServerName $Domain
    ServerAlias *.$Domain $IpAddress

    SSLEngine on
    SSLCertificateFile "$certApachePath"
    SSLCertificateKeyFile "$keyApachePath"

    <Directory "$publicApachePath">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@

Set-Content -LiteralPath $vhostPath -Value $vhost -Encoding ASCII

$httpd = @(Get-Content -LiteralPath $httpdConf)
$laragonApachePath = $laragonApacheEtc.Replace('\', '/')

$sslModuleLine = 'LoadModule ssl_module modules/mod_ssl.so'

if (-not ($httpd -contains $sslModuleLine)) {
    if ($httpd -match '^#LoadModule\s+ssl_module\s+modules/mod_ssl\.so') {
        $httpd = $httpd -replace '^#LoadModule\s+ssl_module\s+modules/mod_ssl\.so', $sslModuleLine
    } else {
        $httpd += $sslModuleLine
    }
}

$requiredIncludes = @(
    "IncludeOptional `"$laragonApachePath/alias/*.conf`"",
    "IncludeOptional `"$laragonApachePath/sites-enabled/*.conf`"",
    "Include `"$laragonApachePath/httpd-ssl.conf`"",
    "Include `"$laragonApachePath/mod_php.conf`""
)

foreach ($include in $requiredIncludes) {
    if (-not ($httpd -contains $include)) {
        $httpd += $include
    }
}

Set-Content -LiteralPath $httpdConf -Value $httpd -Encoding ASCII

if (Test-Path -LiteralPath $envPath) {
    Set-EnvValue -Path $envPath -Key 'APP_URL' -Value "https://$IpAddress"
}

if ($php) {
    Push-Location $ProjectPath
    try {
        & $php.FullName artisan optimize:clear | Out-Null
    } finally {
        Pop-Location
    }
}

if (-not $SkipFirewall) {
    foreach ($port in 80, 443) {
        $ruleName = "Attendance Monitoring HTTPS $port"
        if (-not (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue)) {
            New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $port | Out-Null
        }
    }
}

$startScript = Join-Path $ProjectPath 'tools\start-attendance-https.ps1'
$taskAction = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$startScript`" -LaragonRoot `"$LaragonRoot`" -CheckSeconds 30"
$taskTrigger = New-ScheduledTaskTrigger -AtStartup
$taskPrincipal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -RunLevel Highest
$taskSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

Register-ScheduledTask `
    -TaskName 'AttendanceMonitoringStartHttps' `
    -Action $taskAction `
    -Trigger $taskTrigger `
    -Principal $taskPrincipal `
    -Settings $taskSettings `
    -Force | Out-Null

& $apache.FullName -t

Get-Process -Name httpd -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 2
Start-Process `
    -FilePath 'powershell.exe' `
    -ArgumentList @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-WindowStyle', 'Hidden',
        '-File', "`"$startScript`"",
        '-LaragonRoot', "`"$LaragonRoot`"",
        '-CheckSeconds', '30'
    ) `
    -WindowStyle Hidden

Write-Host "HTTPS is configured for https://$IpAddress"
Write-Host "Apache watchdog task registered: AttendanceMonitoringStartHttps"
Write-Host "Certificate warning is expected unless the local certificate is trusted on the client PC."

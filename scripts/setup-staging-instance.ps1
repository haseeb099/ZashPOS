# Isolated staging ZashPOS: own vendor, .env, SQLite. Removes shipped ZashDine
# so Modules UI / ZIP install can be proven. Does not purchase hosting.
param(
    [string]$StagingRoot = 'D:\Nexo\ZashPOS-staging-deploy',
    [string]$SourceRoot = '',
    [int]$Port = 8016,
    [switch]$SkipComposer
)

$ErrorActionPreference = 'Continue'
$SourceRoot = if ($SourceRoot) { $SourceRoot } else { Split-Path -Parent $PSScriptRoot }
$log = Join-Path $SourceRoot 'storage\logs\phase5-staging-setup.txt'
New-Item -ItemType Directory -Force -Path (Split-Path $log) | Out-Null

function Log([string]$msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'o'), $msg
    Add-Content -Path $log -Value $line
    Write-Host $line
}

function Assert-LastExit([string]$what) {
    if ($null -ne $LASTEXITCODE -and $LASTEXITCODE -ne 0) {
        throw "$what failed: $LASTEXITCODE"
    }
}

function Set-EnvValue([string]$file, [string]$key, [string]$value) {
    $content = Get-Content $file -Raw
    if ($content -match "(?m)^$([regex]::Escape($key))=") {
        $content = [regex]::Replace($content, "(?m)^$([regex]::Escape($key))=.*$", "$key=$value")
    } else {
        $content = $content.TrimEnd() + "`r`n$key=$value`r`n"
    }
    Set-Content -Path $file -Value $content -NoNewline
}

Log '=== Staging setup start ==='
Log "Source=$SourceRoot Staging=$StagingRoot Port=$Port"

Set-Location $SourceRoot
$env:ZASHPOS_PREFER_LOCAL_APP = '1'
$env:APP_BASE_PATH = $SourceRoot

Log 'Packaging ZashDine…'
& php artisan zash-dine:package --no-interaction 2>&1 | ForEach-Object {
    $text = "$_"
    if ($text -notmatch '^FIND: Parameter format not correct') { Log $text }
}
Assert-LastExit 'package'

$zip = Get-ChildItem (Join-Path $SourceRoot 'storage\app\zashdine-packages\ZashDine-*.zip') |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1
if (-not $zip) { throw 'ZIP not found' }
$sha = (Get-FileHash $zip.FullName -Algorithm SHA256).Hash
$hostRev = (git -C $SourceRoot rev-parse HEAD).Trim()
Log ("ZIP={0} SHA256={1} HOST_REV={2}" -f $zip.Name, $sha, $hostRev)

@{
    built_at = (Get-Date -Format o)
    zip_path = $zip.Name
    sha256 = $sha
    bytes = $zip.Length
    host_git_revision = $hostRev
    required_core_min_version = '6.3.0'
    required_capabilities = @('order-product-canonical-unit-price', 'pos-cart-products-mergeable')
    staging_root = $StagingRoot
    staging_port = $Port
} | ConvertTo-Json | Set-Content (Join-Path $SourceRoot 'storage\logs\phase5-deployment-zip-manifest.json')

Push-Location $SourceRoot
git worktree prune 2>&1 | ForEach-Object { Log "$_" }
if (Test-Path $StagingRoot) {
    Log "Removing existing $StagingRoot"
    git worktree remove --force $StagingRoot 2>&1 | ForEach-Object { Log "$_" }
    if (Test-Path $StagingRoot) { Remove-Item -Recurse -Force $StagingRoot }
}

Log 'Creating staging worktree…'
git worktree add --force --detach $StagingRoot HEAD 2>&1 | ForEach-Object { Log "$_" }
if ($LASTEXITCODE -ne 0) {
    New-Item -ItemType Directory -Force -Path $StagingRoot | Out-Null
    $prefix = $StagingRoot.TrimEnd('\') + '\'
    git checkout-index -a -f --prefix=$prefix
}
Pop-Location

$moduleDir = Join-Path $StagingRoot 'modules\ZashDine'
if (Test-Path $moduleDir) {
    Log 'Removing shipped ZashDine for Modules-upload proof'
    Remove-Item -Recurse -Force $moduleDir
}

$dbPath = Join-Path $StagingRoot 'database\staging.sqlite'
New-Item -ItemType Directory -Force -Path (Split-Path $dbPath) | Out-Null
if (Test-Path $dbPath) { Remove-Item -Force $dbPath }
New-Item -ItemType File -Force -Path $dbPath | Out-Null
Copy-Item (Join-Path $StagingRoot '.env.example') (Join-Path $StagingRoot '.env') -Force

$envFile = Join-Path $StagingRoot '.env'
Set-EnvValue $envFile 'APP_NAME' 'ZashPOS-Staging'
Set-EnvValue $envFile 'APP_ENV' 'local'
Set-EnvValue $envFile 'APP_DEBUG' 'true'
Set-EnvValue $envFile 'APP_URL' "http://127.0.0.1:$Port"
Set-EnvValue $envFile 'DB_CONNECTION' 'sqlite'
Set-EnvValue $envFile 'DB_DATABASE' ($dbPath.Replace('\', '/'))
Set-EnvValue $envFile 'DB_USERNAME' 'root'
Set-EnvValue $envFile 'DB_PASSWORD' 'unused'
Set-EnvValue $envFile 'SESSION_DOMAIN' '127.0.0.1'
Set-EnvValue $envFile 'BROADCAST_DRIVER' 'log'
Set-EnvValue $envFile 'QUEUE_CONNECTION' 'sync'
Set-EnvValue $envFile 'SANCTUM_STATEFUL_DOMAINS' "127.0.0.1:$Port"

Push-Location $StagingRoot
if (-not $SkipComposer) {
    Log 'composer install (own vendor)…'
    $env:COMPOSER_PROCESS_TIMEOUT = '0'
    cmd /c "composer install --no-interaction --prefer-dist --no-scripts >composer-install.log 2>&1"
    Assert-LastExit 'composer install'
    composer dump-autoload --optimize --no-interaction | Out-Null
}

php artisan key:generate --force --no-interaction 2>&1 | ForEach-Object { Log "$_" }
Assert-LastExit 'key:generate'

Log 'ns:setup on empty SQLite…'
php artisan ns:setup --store_name="ZashPOS Staging" --admin_username=admin --admin_email=admin@example.com --admin_password=password --language=en --no-interaction 2>&1 | ForEach-Object { Log "$_" }
Assert-LastExit 'ns:setup'

New-Item -ItemType Directory -Force -Path (Join-Path $StagingRoot 'storage\app\zashdine-packages') | Out-Null
Copy-Item $zip.FullName (Join-Path $StagingRoot 'storage\app\zashdine-packages\') -Force

if (Test-Path (Join-Path $SourceRoot 'public\build')) {
    $buildTarget = Join-Path $StagingRoot 'public\build'
    if (-not (Test-Path $buildTarget)) {
        Log 'Copying public/build for dashboard UI…'
        Copy-Item (Join-Path $SourceRoot 'public\build') $buildTarget -Recurse -Force
    }
}

Pop-Location
Log '=== Staging setup finished (ZashDine absent — ready for browser Modules upload) ==='
Log "Admin: admin / password  URL: http://127.0.0.1:$Port"
Write-Host "OK — see $log"

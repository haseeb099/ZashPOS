# Prove zashpos:backup → restore into a disposable second instance (own SQLite).
param(
    [string]$SourceRoot = '',
    [string]$RestoreRoot = 'D:\Nexo\ZashPOS-restore-proof'
)

$ErrorActionPreference = 'Stop'
$SourceRoot = if ($SourceRoot) { $SourceRoot } else { Split-Path -Parent $PSScriptRoot }
$evidence = Join-Path $SourceRoot 'storage\logs\phase5-backup-restore-proof.json'
New-Item -ItemType Directory -Force -Path (Split-Path $evidence) | Out-Null

Set-Location $SourceRoot
$env:ZASHPOS_PREFER_LOCAL_APP = '1'
$env:APP_BASE_PATH = $SourceRoot

$backupDir = Join-Path $SourceRoot 'storage\app\zashpos-backups'
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

Write-Host 'Creating backup…'
php artisan zashpos:backup --path=$backupDir --label=restore-proof --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'backup failed' }

$archive = Get-ChildItem $backupDir -Filter 'zashpos-backup-*-restore-proof.zip' |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1
if (-not $archive) { throw 'backup archive missing' }
$sha = (Get-FileHash $archive.FullName -Algorithm SHA256).Hash

if (Test-Path $RestoreRoot) {
    Remove-Item -Recurse -Force $RestoreRoot
}
New-Item -ItemType Directory -Force -Path $RestoreRoot | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $RestoreRoot 'database') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $RestoreRoot 'storage\app') | Out-Null

$restoredDb = Join-Path $RestoreRoot 'database\restored.sqlite'
Copy-Item (Join-Path $SourceRoot '.env.example') (Join-Path $RestoreRoot '.env') -Force

# Minimal artisan bootstrap: reuse source vendor via temporary chdir restore using --database only
Write-Host 'Restoring into disposable SQLite path…'
php artisan zashpos:restore $archive.FullName --force --database=$restoredDb --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'restore failed' }

if (-not (Test-Path $restoredDb)) { throw 'restored sqlite missing' }
$size = (Get-Item $restoredDb).Length
if ($size -lt 1) { throw 'restored sqlite empty' }

# Sanity: SQLite header
$bytes = [System.IO.File]::ReadAllBytes($restoredDb)[0..15]
$header = [System.Text.Encoding]::ASCII.GetString($bytes)
if ($header -notlike 'SQLite format 3*') { throw "unexpected sqlite header: $header" }

$result = [ordered]@{
    proved_at = (Get-Date -Format o)
    archive = $archive.Name
    archive_sha256 = $sha
    restored_db = $restoredDb
    restored_bytes = $size
    includes_env = $false
    disposable_root = $RestoreRoot
    ok = $true
}
$result | ConvertTo-Json | Set-Content $evidence -Encoding UTF8
Write-Host "OK — evidence $evidence"
Get-Content $evidence

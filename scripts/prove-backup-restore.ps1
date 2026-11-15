# Prove zashpos:backup → restore DB + storage into a disposable tree.
# Asserts source DB/storage hashes unchanged and validates restored rows + file bytes.
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
$proofStorage = Join-Path $SourceRoot 'storage\app\zashpos-restore-proof-marker'
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $proofStorage 'proof-marker') | Out-Null
$probePath = Join-Path $proofStorage 'proof-marker\probe.txt'
$probeValue = "restore-proof-$(Get-Date -Format 'yyyyMMddHHmmss')"
Set-Content -Path $probePath -Value $probeValue -NoNewline

# Resolve sqlite path from .env / phpunit-less artisan config
$dbPath = php -r "require 'vendor/autoload.php'; `$app=require 'bootstrap/app.php'; `$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); `$db=config('database.connections.sqlite.database'); if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', `$db) && !str_starts_with(`$db,'/')) { `$db=base_path(`$db); } echo `$db;"
if (-not (Test-Path $dbPath)) { throw "SQLite missing: $dbPath" }

$sourceDbHashBefore = (Get-FileHash $dbPath -Algorithm SHA256).Hash
$sourceProbeBefore = Get-Content $probePath -Raw

Write-Host 'Creating backup (storage-app limited to proof marker tree)…'
php artisan zashpos:backup --path=$backupDir --label=restore-proof --storage-app=$proofStorage --no-interaction
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
$restoredDb = Join-Path $RestoreRoot 'database\restored.sqlite'
$restoredStorage = Join-Path $RestoreRoot 'storage\app'
New-Item -ItemType Directory -Force -Path (Split-Path $restoredDb) | Out-Null
New-Item -ItemType Directory -Force -Path $restoredStorage | Out-Null

Write-Host 'Restoring into disposable DB + storage paths…'
php artisan zashpos:restore $archive.FullName --force --database=$restoredDb --storage-app=$restoredStorage --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'restore failed' }

$sourceDbHashAfter = (Get-FileHash $dbPath -Algorithm SHA256).Hash
$sourceProbeAfter = Get-Content $probePath -Raw
if ($sourceDbHashBefore -ne $sourceDbHashAfter) { throw 'Source SQLite changed during restore' }
if ($sourceProbeBefore -ne $sourceProbeAfter) { throw 'Source storage probe changed during restore' }

if (-not (Test-Path $restoredDb)) { throw 'restored sqlite missing' }
$restoredProbe = Join-Path $restoredStorage 'proof-marker\probe.txt'
if (-not (Test-Path $restoredProbe)) { throw 'restored probe file missing' }
$restoredProbeValue = Get-Content $restoredProbe -Raw
if ($restoredProbeValue -ne $probeValue) { throw "restored probe mismatch: $restoredProbeValue" }

$validator = Join-Path $SourceRoot 'scripts\validate-restored-sqlite.php'
$tableCheck = & php $validator $restoredDb
if ($LASTEXITCODE -ne 0) { throw "restored sqlite validation failed: $tableCheck" }

$result = [ordered]@{
    proved_at = (Get-Date -Format o)
    archive = $archive.Name
    archive_sha256 = $sha
    sqlite_snapshot = 'vacuum-into'
    restored_db = $restoredDb
    restored_storage = $restoredStorage
    restored_probe = $probeValue
    restored_sqlite_master_rows = [int]$tableCheck
    source_db_unchanged = $true
    source_storage_unchanged = $true
    includes_env = $false
    disposable_root = $RestoreRoot
    ok = $true
}
$result | ConvertTo-Json | Set-Content $evidence -Encoding UTF8
Write-Host "OK — evidence $evidence"
Get-Content $evidence

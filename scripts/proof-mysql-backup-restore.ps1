# Windows entrypoint: fails closed unless mysqldump/mysql are on PATH and env is set.
# Prefer scripts/proof-mysql-backup-restore.sh on the Linux staging host.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$bashScript = Join-Path $PSScriptRoot 'proof-mysql-backup-restore.sh'

function Require-Env([string]$Name) {
    $v = [Environment]::GetEnvironmentVariable($Name)
    if ([string]::IsNullOrWhiteSpace($v)) {
        throw "Missing required env var $Name (set via secure channel / shell — do not commit)."
    }
}

Require-Env 'MYSQL_HOST'
Require-Env 'MYSQL_USER'
Require-Env 'MYSQL_PASSWORD'
Require-Env 'MYSQL_SOURCE_DATABASE'
Require-Env 'MYSQL_TARGET_DATABASE'

$mysqldump = Get-Command mysqldump -ErrorAction SilentlyContinue
$mysql = Get-Command mysql -ErrorAction SilentlyContinue
if (-not $mysqldump -or -not $mysql) {
    Write-Host 'BLOCKED: mysqldump/mysql clients not available on this machine.'
    Write-Host 'Remote staging MySQL proof must run on the staging host (or a workstation with clients + credentials).'
    Write-Host 'See docs/zashpos/BACKUP-RESTORE.md § MySQL / MariaDB.'
    exit 2
}

$bash = Get-Command bash -ErrorAction SilentlyContinue
if (-not $bash) {
    throw 'bash not found; run proof-mysql-backup-restore.sh on Linux or install Git Bash.'
}

& bash $bashScript
exit $LASTEXITCODE

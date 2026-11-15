# Windows entrypoint for MySQL proof / regressions.
# Prefer scripts/proof-mysql-backup-restore.sh on the Linux staging host.
param(
    [switch]$Regressions
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$bashScript = Join-Path $PSScriptRoot 'proof-mysql-backup-restore.sh'
$regScript = Join-Path $PSScriptRoot 'proof-mysql-backup-restore-regressions.sh'

function Resolve-GitBash {
    $candidates = @(
        'C:\Program Files\Git\bin\bash.exe',
        'C:\Program Files\Git\usr\bin\bash.exe'
    )
    foreach ($c in $candidates) {
        if (Test-Path $c) {
            return $c
        }
    }
    $cmd = Get-Command bash -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source -notmatch '\\WindowsApps\\' -and $cmd.Source -notmatch '\\wsl') {
        return $cmd.Source
    }
    return $null
}

$bashExe = Resolve-GitBash
if (-not $bashExe) {
    throw 'Git Bash not found; run the .sh scripts on Linux or install Git for Windows.'
}

if ($Regressions) {
    & $bashExe $regScript
    exit $LASTEXITCODE
}

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
# MYSQL_TARGET_DATABASE is optional: the shell script generates a unique name when omitted.

$mysqldump = Get-Command mysqldump -ErrorAction SilentlyContinue
$mysql = Get-Command mysql -ErrorAction SilentlyContinue
$dryRun = [Environment]::GetEnvironmentVariable('PROOF_MYSQL_DRY_RUN')
if ((-not $mysqldump -or -not $mysql) -and $dryRun -ne '1') {
    Write-Host 'BLOCKED: mysqldump/mysql clients not available on this machine.'
    Write-Host 'Real MySQL restore proof remains pending until staging host access + clients exist.'
    Write-Host 'Run regressions without MySQL:  .\scripts\proof-mysql-backup-restore.ps1 -Regressions'
    Write-Host 'See docs/zashpos/BACKUP-RESTORE.md § MySQL / MariaDB.'
    exit 2
}

& $bashExe $bashScript
exit $LASTEXITCODE

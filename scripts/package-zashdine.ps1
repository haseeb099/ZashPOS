# Build a reproducible ZashDine ZIP (Modules upload).
# Usage: .\scripts\package-zashdine.ps1 [-OutputDir path]
param(
    [string]$OutputDir = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$env:ZASHPOS_PREFER_LOCAL_APP = '1'
$env:APP_BASE_PATH = $root

$argsList = @('zash-dine:package', '--no-interaction')
if ($OutputDir -ne '') {
    $argsList += "--output=$OutputDir"
}

& php artisan @argsList
exit $LASTEXITCODE

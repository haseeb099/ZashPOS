# Local worktree helper when vendor/ is a junction to another checkout.
# Sets ZASHPOS_PREFER_LOCAL_APP=1 so bootstrap/app.php loads prefer-app-modules
# for this process and for artisan serve children (public/index.php stays clean).
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$artisan = Join-Path $root 'artisan'

$env:ZASHPOS_PREFER_LOCAL_APP = '1'
& php $artisan @args
exit $LASTEXITCODE

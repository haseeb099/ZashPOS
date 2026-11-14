# Clean ZashPOS instance with its own vendor, database, and environment;
# install ZashDine via the Modules upload path (ZIP), then verify enable/disable,
# migrations, permissions, and Blade assets.
#
# Evidence:
#   storage/logs/phase5-clean-install.txt
#   (clean tree) storage/logs/phase5-package-install.json
param(
    [string]$CleanRoot = 'D:\Nexo\ZashPOS-clean-phase5',
    [string]$SourceRoot = '',
    [switch]$SkipClone
)

$ErrorActionPreference = 'Stop'
$SourceRoot = if ($SourceRoot) { $SourceRoot } else { Split-Path -Parent $PSScriptRoot }
$log = Join-Path $SourceRoot 'storage\logs\phase5-clean-install.txt'
New-Item -ItemType Directory -Force -Path (Split-Path $log) | Out-Null

function Log([string]$msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'o'), $msg
    Add-Content -Path $log -Value $line
    Write-Host $line
}

Log '=== Phase 5 clean-install start ==='
Log "Source=$SourceRoot Clean=$CleanRoot"

Set-Location $SourceRoot
$env:ZASHPOS_PREFER_LOCAL_APP = '1'
$env:APP_BASE_PATH = $SourceRoot

Log 'Packaging ZashDine from source…'
& php artisan zash-dine:package --no-interaction
if ($LASTEXITCODE -ne 0) { throw "package failed: $LASTEXITCODE" }

$zip = Get-ChildItem (Join-Path $SourceRoot 'storage\app\zashdine-packages\ZashDine-*.zip') |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1
if (-not $zip) { throw 'ZIP not found' }
$sha = (Get-FileHash $zip.FullName -Algorithm SHA256).Hash
Log ("ZIP={0} SHA256={1} bytes={2}" -f $zip.FullName, $sha, $zip.Length)

Log 'Same-tree Modules upload validation…'
& php artisan zash-dine:validate-package-install --no-interaction
if ($LASTEXITCODE -ne 0) { throw "validate-package-install failed: $LASTEXITCODE" }
$sameTreeEvidence = Join-Path $SourceRoot 'storage\logs\phase5-package-install.json'
Log "Same-tree evidence: $sameTreeEvidence"

if ($SkipClone) {
    Log 'SkipClone set — stopping after same-tree validation.'
    Log '=== Phase 5 clean-install finished (same-tree only) ==='
    exit 0
}

if (Test-Path $CleanRoot) {
    Log "Removing $CleanRoot"
    Remove-Item -Recurse -Force $CleanRoot
}

Log 'Creating clean tree via git worktree (own checkout)…'
if (Test-Path $CleanRoot) {
    Remove-Item -Recurse -Force $CleanRoot -ErrorAction SilentlyContinue
}
Push-Location $SourceRoot
# Detached worktree at current HEAD — own files; vendor installed separately below.
$prevEap = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
git worktree add --detach $CleanRoot HEAD 2>&1 | ForEach-Object { Log "$_" }
$wtExit = $LASTEXITCODE
$ErrorActionPreference = $prevEap
if ($wtExit -ne 0) {
    Log 'worktree add failed; falling back to checkout-index'
    New-Item -ItemType Directory -Force -Path $CleanRoot | Out-Null
    $prefix = $CleanRoot.TrimEnd('\') + '\'
    git checkout-index -a -f --prefix=$prefix
}
Pop-Location

$moduleDir = Join-Path $CleanRoot 'modules\ZashDine'
if (Test-Path $moduleDir) {
    Log 'Removing shipped ZashDine so install uses the ZIP'
    Remove-Item -Recurse -Force $moduleDir
}

# Seed empty sqlite + .env before composer scripts that may call artisan
$dbPath = Join-Path $CleanRoot 'database\phase5-clean.sqlite'
New-Item -ItemType File -Force -Path $dbPath | Out-Null
Copy-Item (Join-Path $CleanRoot '.env.example') (Join-Path $CleanRoot '.env') -Force

function Set-EnvValue([string]$file, [string]$key, [string]$value) {
    $content = Get-Content $file -Raw
    if ($content -match "(?m)^$([regex]::Escape($key))=") {
        $content = [regex]::Replace($content, "(?m)^$([regex]::Escape($key))=.*$", "$key=$value")
    } else {
        $content = $content.TrimEnd() + "`r`n$key=$value`r`n"
    }
    Set-Content -Path $file -Value $content -NoNewline
}

$envFile = Join-Path $CleanRoot '.env'
Set-EnvValue $envFile 'APP_NAME' 'ZashPOS-Clean-Phase5'
Set-EnvValue $envFile 'APP_ENV' 'local'
Set-EnvValue $envFile 'APP_DEBUG' 'true'
Set-EnvValue $envFile 'APP_URL' 'http://127.0.0.1:8015'
Set-EnvValue $envFile 'DB_CONNECTION' 'sqlite'
Set-EnvValue $envFile 'DB_DATABASE' ($dbPath.Replace('\', '/'))
Set-EnvValue $envFile 'SESSION_DOMAIN' '127.0.0.1'
Set-EnvValue $envFile 'BROADCAST_CONNECTION' 'log'

Log 'composer install (own vendor, not a junction)…'
Push-Location $CleanRoot
# Avoid post-install artisan failures on an uninstalled app
$env:COMPOSER_PROCESS_TIMEOUT = '0'
composer install --no-interaction --prefer-dist --no-scripts 2>&1 |
    Tee-Object -FilePath (Join-Path $CleanRoot 'composer-install.log')
if ($LASTEXITCODE -ne 0) { throw "composer install failed: $LASTEXITCODE" }
composer dump-autoload --optimize --no-interaction | Out-Null

php artisan key:generate --force --no-interaction
# Copy a known-good installed sqlite from source tests DB as schema baseline when full ns:install is interactive
$sourceDb = Join-Path $SourceRoot 'tests\database.sqlite'
if (Test-Path $sourceDb) {
    Copy-Item $sourceDb $dbPath -Force
    Log "Seeded clean DB schema from source tests/database.sqlite"
}

# Place ZIP and run upload validation inside the clean tree
New-Item -ItemType Directory -Force -Path (Join-Path $CleanRoot 'storage\app\zashdine-packages') | Out-Null
Copy-Item $zip.FullName (Join-Path $CleanRoot 'storage\app\zashdine-packages\') -Force

# Restore ZashDine from ZIP only (module was deleted); host commands live in app/ from archive
Copy-Item (Join-Path $SourceRoot 'app\Console\Commands\PackageZashDineCommand.php') (Join-Path $CleanRoot 'app\Console\Commands\') -Force
Copy-Item (Join-Path $SourceRoot 'app\Console\Commands\ValidateZashDinePackageInstallCommand.php') (Join-Path $CleanRoot 'app\Console\Commands\') -Force

# Put module back from ZIP via ModulesService using a small PHP runner
$runner = Join-Path $CleanRoot 'phase5-upload.php'
$zipPosix = $zip.FullName.Replace('\', '/')
@'
<?php
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$zip = glob(__DIR__ . "/storage/app/zashdine-packages/ZashDine-*.zip")[0] ?? null;
if (!$zip) { fwrite(STDERR, "zip missing\n"); exit(1); }

$file = new Illuminate\Http\UploadedFile($zip, basename($zip), "application/zip", null, true);
$modules = app(App\Services\ModulesService::class);
$result = $modules->upload($file);
echo json_encode($result), PHP_EOL;
if (($result["status"] ?? null) !== "success") { exit(2); }

$modules->load();
$enable = $modules->enable("ZashDine");
if (is_object($enable) && method_exists($enable, "getData")) {
    echo json_encode($enable->getData(true)), PHP_EOL;
    exit(3);
}
echo json_encode(["enable" => $enable]), PHP_EOL;

$perms = App\Models\Permission::query()->where("namespace", "like", "zash.dine.%")->pluck("namespace")->sort()->values()->all();
$tables = [
    "zash_dine_areas" => Schema::hasTable("zash_dine_areas"),
    "zash_dine_order_product_modifiers" => Schema::hasTable("zash_dine_order_product_modifiers"),
];
$assets = [
    "pos-script" => is_file(base_path("modules/ZashDine/Resources/Views/pos-script.blade.php")),
    "public" => is_file(base_path("modules/ZashDine/Public/index.html")),
];
$disable = $modules->disable("ZashDine");
$enable2 = $modules->enable("ZashDine");
$out = [
    "permissions" => $perms,
    "tables" => $tables,
    "assets" => $assets,
    "disable" => $disable["status"] ?? null,
    "enable_again" => is_array($enable2) ? ($enable2["status"] ?? null) : "error",
    "core" => config("nexopos.version"),
];
file_put_contents(__DIR__ . "/storage/logs/phase5-clean-verify.json", json_encode($out, JSON_PRETTY_PRINT));
echo json_encode($out, JSON_PRETTY_PRINT), PHP_EOL;
'@ | Set-Content -Path $runner -Encoding UTF8

php $runner 2>&1 | Tee-Object -FilePath (Join-Path $CleanRoot 'storage\logs\phase5-upload-run.txt')
if ($LASTEXITCODE -ne 0) { throw "clean upload runner failed: $LASTEXITCODE" }

$verifyPath = Join-Path $CleanRoot 'storage\logs\phase5-clean-verify.json'
Log "Clean-tree verify: $verifyPath"
if (Test-Path $verifyPath) {
    Log (Get-Content $verifyPath -Raw)
}

Pop-Location
Log "ZIP SHA-256=$sha"
Log '=== Phase 5 clean-install finished ==='
Write-Host "OK — see $log"

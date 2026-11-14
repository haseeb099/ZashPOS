# Clean ZashPOS instance with its own vendor, database, and environment;
# install ZashDine via the Modules upload path (ZIP), then verify enable/disable,
# migrations, permissions, and Blade assets.
#
# Evidence:
#   storage/logs/phase5-clean-install.txt
#   (clean tree) storage/logs/phase5-clean-verify.json
param(
    [string]$CleanRoot = 'D:\Nexo\ZashPOS-clean-phase5',
    [string]$SourceRoot = '',
    [switch]$SkipClone
)

# Continue on native stderr (composer progress, ZipArchive FIND noise on Windows).
# Failures are enforced via $LASTEXITCODE / explicit throws below.
$ErrorActionPreference = 'Continue'
$SourceRoot = if ($SourceRoot) { $SourceRoot } else { Split-Path -Parent $PSScriptRoot }
$log = Join-Path $SourceRoot 'storage\logs\phase5-clean-install.txt'
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

Log '=== Phase 5 clean-install start ==='
Log "Source=$SourceRoot Clean=$CleanRoot"

Set-Location $SourceRoot
$env:ZASHPOS_PREFER_LOCAL_APP = '1'
$env:APP_BASE_PATH = $SourceRoot

Log 'Packaging ZashDine from source…'
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
Log ("ZIP={0} SHA256={1} bytes={2}" -f $zip.FullName, $sha, $zip.Length)

Log 'Same-tree Modules upload validation…'
& php artisan zash-dine:validate-package-install --no-interaction 2>&1 | ForEach-Object {
    $text = "$_"
    if ($text -notmatch '^FIND: Parameter format not correct') { Log $text }
}
Assert-LastExit 'validate-package-install'
$sameTreeEvidence = Join-Path $SourceRoot 'storage\logs\phase5-package-install.json'
Log "Same-tree evidence: $sameTreeEvidence"

if ($SkipClone) {
    Log 'SkipClone set — stopping after same-tree validation.'
    Log '=== Phase 5 clean-install finished (same-tree only) ==='
    exit 0
}

Push-Location $SourceRoot
Log 'Pruning stale git worktrees…'
git worktree prune 2>&1 | ForEach-Object { Log "$_" }
if (Test-Path $CleanRoot) {
    Log "Removing $CleanRoot"
    git worktree remove --force $CleanRoot 2>&1 | ForEach-Object { Log "$_" }
    if (Test-Path $CleanRoot) {
        Remove-Item -Recurse -Force $CleanRoot
    }
}

Log 'Creating clean tree via git worktree (own checkout)…'
git worktree add --force --detach $CleanRoot HEAD 2>&1 | ForEach-Object { Log "$_" }
$wtExit = $LASTEXITCODE
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

# Fresh empty sqlite — never copy a populated tests DB (would hide migration failures).
$dbPath = Join-Path $CleanRoot 'database\phase5-clean.sqlite'
New-Item -ItemType Directory -Force -Path (Split-Path $dbPath) | Out-Null
if (Test-Path $dbPath) { Remove-Item -Force $dbPath }
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
Set-EnvValue $envFile 'DB_HOST' '127.0.0.1'
Set-EnvValue $envFile 'DB_DATABASE' ($dbPath.Replace('\', '/'))
Set-EnvValue $envFile 'DB_USERNAME' 'root'
Set-EnvValue $envFile 'DB_PASSWORD' 'unused'
Set-EnvValue $envFile 'SESSION_DOMAIN' '127.0.0.1'
Set-EnvValue $envFile 'BROADCAST_CONNECTION' 'log'

Log 'composer install (own vendor, not a junction)…'
Push-Location $CleanRoot
$env:COMPOSER_PROCESS_TIMEOUT = '0'
cmd /c "composer install --no-interaction --prefer-dist --no-scripts >composer-install.log 2>&1"
Assert-LastExit 'composer install'
composer dump-autoload --optimize --no-interaction | Out-Null

php artisan key:generate --force --no-interaction 2>&1 | ForEach-Object { Log "$_" }
Assert-LastExit 'key:generate'

Log 'Fresh core install via ns:setup (empty database)…'
php artisan ns:setup --store_name="Phase5 Clean" --admin_username=admin --admin_email=admin@example.com --admin_password=password --language=en --no-interaction 2>&1 | ForEach-Object { Log "$_" }
Assert-LastExit 'ns:setup'

# Place ZIP and host validate helpers
New-Item -ItemType Directory -Force -Path (Join-Path $CleanRoot 'storage\app\zashdine-packages') | Out-Null
Copy-Item $zip.FullName (Join-Path $CleanRoot 'storage\app\zashdine-packages\') -Force
Copy-Item (Join-Path $SourceRoot 'app\Console\Commands\PackageZashDineCommand.php') (Join-Path $CleanRoot 'app\Console\Commands\') -Force
Copy-Item (Join-Path $SourceRoot 'app\Console\Commands\ValidateZashDinePackageInstallCommand.php') (Join-Path $CleanRoot 'app\Console\Commands\') -Force

$runner = Join-Path $CleanRoot 'phase5-upload.php'
@'
<?php
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertZashDineAbsent(string $phase): void
{
    $tables = [
        'zash_dine_areas',
        'zash_dine_tables',
        'zash_dine_kitchens',
        'zash_dine_order_items',
        'zash_dine_modifier_groups',
        'zash_dine_modifiers',
        'zash_dine_product_modifier_groups',
        'zash_dine_order_product_modifiers',
    ];

    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            fail("{$phase}: unexpected table {$table} before ZashDine upload");
        }
    }

    if (Permission::query()->where('namespace', 'like', 'zash.dine.%')->exists()) {
        fail("{$phase}: unexpected zash.dine.* permissions before upload");
    }

    if (Schema::hasTable('nexopos_modules_migrations')) {
        $migrationRows = DB::table('nexopos_modules_migrations')
            ->where('namespace', 'ZashDine')
            ->count();
        if ($migrationRows > 0) {
            fail("{$phase}: unexpected ZashDine migration records ({$migrationRows}) before upload");
        }
    } else {
        fail("{$phase}: core schema missing nexopos_modules_migrations after ns:setup");
    }

    if (is_dir(base_path('modules/ZashDine'))) {
        fail("{$phase}: modules/ZashDine must be absent before ZIP upload");
    }
}

function assertZashDinePresent(array $permissions, array $tables, array $assets): void
{
    $requiredPerms = ['zash.dine.manage', 'zash.dine.waiter', 'zash.dine.chef'];
    $missingPerms = array_values(array_diff($requiredPerms, $permissions));
    if ($missingPerms !== []) {
        fail('post: missing permissions: ' . implode(', ', $missingPerms));
    }

    $missingTables = [];
    foreach ($tables as $name => $ok) {
        if (!$ok) {
            $missingTables[] = $name;
        }
    }
    if ($missingTables !== []) {
        fail('post: missing tables: ' . implode(', ', $missingTables));
    }

    $missingAssets = [];
    foreach ($assets as $name => $ok) {
        if (!$ok) {
            $missingAssets[] = $name;
        }
    }
    if ($missingAssets !== []) {
        fail('post: missing assets: ' . implode(', ', $missingAssets));
    }

    $migrationRows = DB::table('nexopos_modules_migrations')
        ->where('namespace', 'ZashDine')
        ->count();
    if ($migrationRows < 1) {
        fail('post: no ZashDine rows in nexopos_modules_migrations');
    }
}

assertZashDineAbsent('pre');

$zip = glob(__DIR__ . "/storage/app/zashdine-packages/ZashDine-*.zip")[0] ?? null;
if (!$zip) {
    fail('zip missing');
}

$file = new Illuminate\Http\UploadedFile($zip, basename($zip), "application/zip", null, true);
$modules = app(App\Services\ModulesService::class);
$result = $modules->upload($file);
echo json_encode($result), PHP_EOL;
if (($result["status"] ?? null) !== "success") {
    fail('upload failed: ' . json_encode($result));
}

$migrate = $modules->runAllMigration('ZashDine');
echo json_encode($migrate), PHP_EOL;
if (($migrate['status'] ?? null) !== 'success') {
    fail('migrate failed: ' . json_encode($migrate));
}

$modules->load();
$enable = $modules->enable("ZashDine");
if (is_object($enable) && method_exists($enable, "getData")) {
    fail('enable failed: ' . json_encode($enable->getData(true)));
}
echo json_encode(["enable" => $enable]), PHP_EOL;

$perms = Permission::query()->where("namespace", "like", "zash.dine.%")->orderBy('namespace')->pluck("namespace")->values()->all();
$tables = [
    "zash_dine_areas" => Schema::hasTable("zash_dine_areas"),
    "zash_dine_tables" => Schema::hasTable("zash_dine_tables"),
    "zash_dine_kitchens" => Schema::hasTable("zash_dine_kitchens"),
    "zash_dine_order_items" => Schema::hasTable("zash_dine_order_items"),
    "zash_dine_modifier_groups" => Schema::hasTable("zash_dine_modifier_groups"),
    "zash_dine_modifiers" => Schema::hasTable("zash_dine_modifiers"),
    "zash_dine_product_modifier_groups" => Schema::hasTable("zash_dine_product_modifier_groups"),
    "zash_dine_order_product_modifiers" => Schema::hasTable("zash_dine_order_product_modifiers"),
];
$assets = [
    "pos-script" => is_file(base_path("modules/ZashDine/Resources/Views/pos-script.blade.php")),
    "public" => is_file(base_path("modules/ZashDine/Public/index.html")),
    "config" => is_file(base_path("modules/ZashDine/config.xml")),
];

$disable = $modules->disable("ZashDine");
if (($disable['status'] ?? null) !== 'success') {
    fail('disable failed: ' . json_encode($disable));
}
$enable2 = $modules->enable("ZashDine");
if (is_object($enable2) && method_exists($enable2, 'getData')) {
    fail('re-enable failed: ' . json_encode($enable2->getData(true)));
}
if (!is_array($enable2) || ($enable2['status'] ?? null) !== 'success') {
    fail('re-enable status not success: ' . json_encode($enable2));
}

assertZashDinePresent($perms, $tables, $assets);

$out = [
    "permissions" => $perms,
    "tables" => $tables,
    "assets" => $assets,
    "disable" => $disable["status"] ?? null,
    "enable_again" => $enable2["status"] ?? null,
    "core" => config("nexopos.version"),
    "host" => config("nexopos.host"),
    "capabilities" => config("nexopos.capabilities"),
    "migration_rows" => DB::table('nexopos_modules_migrations')->where('namespace', 'ZashDine')->count(),
    "fresh_db" => true,
];
file_put_contents(__DIR__ . "/storage/logs/phase5-clean-verify.json", json_encode($out, JSON_PRETTY_PRINT));
echo json_encode($out, JSON_PRETTY_PRINT), PHP_EOL;
'@ | Set-Content -Path $runner -Encoding UTF8

cmd /c "php phase5-upload.php >storage\logs\phase5-upload-run.txt 2>&1"
Assert-LastExit 'clean upload runner'

$verifyPath = Join-Path $CleanRoot 'storage\logs\phase5-clean-verify.json'
if (-not (Test-Path $verifyPath)) {
    throw "Missing verify evidence: $verifyPath"
}
Log "Clean-tree verify: $verifyPath"
Log (Get-Content $verifyPath -Raw)

$verify = Get-Content $verifyPath -Raw | ConvertFrom-Json
if (-not $verify.fresh_db) { throw 'verify.fresh_db must be true' }
if ($verify.permissions.Count -lt 3) { throw 'verify missing permissions' }
foreach ($name in @('zash_dine_areas', 'zash_dine_order_product_modifiers')) {
    if (-not $verify.tables.$name) { throw "verify missing table $name" }
}
if (-not $verify.assets.'pos-script') { throw 'verify missing pos-script asset' }
if ($verify.migration_rows -lt 1) { throw 'verify missing migration rows' }

Pop-Location
Log "ZIP SHA-256=$sha"
Log '=== Phase 5 clean-install finished ==='
Write-Host "OK — see $log"

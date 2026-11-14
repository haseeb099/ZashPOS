<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Services\ModulesService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\ZashDine\Services\PackageService;

/**
 * Simulates Modules-page install: package → remove local module → upload ZIP →
 * migrate → enable/disable → assert permissions/tables/assets.
 */
class ValidateZashDinePackageInstallCommand extends Command
{
    protected $signature = 'zash-dine:validate-package-install
                            {--keep-backup : Leave modules/ZashDine.bak in place on failure}';

    protected $description = 'Validate ZashDine ZIP install through ModulesService (package upload path)';

    public function handle( PackageService $packages, ModulesService $modules ): int
    {
        $modulePath = base_path( 'modules/ZashDine' );
        // Outside modules/ so discovery does not pick up the backup folder.
        $backupPath = storage_path( 'app/zashdine-bak-validate' );
        $evidence = storage_path( 'logs/phase5-package-install.json' );

        $report = [
            'started_at' => now()->toIso8601String(),
            'steps' => [],
            'database' => config( 'database.connections.' . config( 'database.default' ) . '.database' ),
        ];

        if ( ! Schema::hasTable( 'nexopos_modules_migrations' ) ) {
            $this->error( 'Database is not an installed ZashPOS schema (missing nexopos_modules_migrations). Refusing to move the module aside.' );
            $report['status'] = 'failed';
            $report['error'] = 'missing nexopos_modules_migrations';
            File::put( $evidence, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

            return self::FAILURE;
        }

        $built = $packages->build();
        $report['package'] = $built;
        $this->info( 'Packaged: ' . $built['path'] );

        if ( is_dir( $backupPath ) ) {
            File::deleteDirectory( $backupPath );
        }

        if ( ! is_dir( $modulePath ) ) {
            $this->error( 'modules/ZashDine missing before validation.' );

            return self::FAILURE;
        }

        File::copyDirectory( $modulePath, $backupPath );
        File::deleteDirectory( $modulePath );
        $report['steps'][] = 'moved_aside';
        $this->forgetModule( $modules, 'ZashDine' );
        $modules->load();

        try {
            $upload = $modules->upload(
                new UploadedFile( $built['path'], basename( $built['path'] ), 'application/zip', null, true )
            );
            $report['upload'] = $upload;

            if ( ( $upload['status'] ?? null ) !== 'success' ) {
                throw new \RuntimeException( 'Upload failed: ' . json_encode( $upload ) );
            }

            $report['steps'][] = 'uploaded';

            $migrate = $modules->runAllMigration( 'ZashDine' );
            $report['migrate'] = $migrate;
            $report['steps'][] = 'migrated';

            $enable = $modules->enable( 'ZashDine' );
            if ( is_object( $enable ) && method_exists( $enable, 'getData' ) ) {
                throw new \RuntimeException( 'Enable failed: ' . json_encode( $enable->getData( true ) ) );
            }
            $report['enable'] = $enable;
            $report['steps'][] = 'enabled';

            $modules->load();
            $module = $modules->get( 'ZashDine' );

            $permissions = Permission::query()
                ->where( 'namespace', 'like', 'zash.dine.%' )
                ->orderBy( 'namespace' )
                ->pluck( 'namespace' )
                ->all();

            $tables = [
                'zash_dine_areas' => Schema::hasTable( 'zash_dine_areas' ),
                'zash_dine_tables' => Schema::hasTable( 'zash_dine_tables' ),
                'zash_dine_kitchens' => Schema::hasTable( 'zash_dine_kitchens' ),
                'zash_dine_order_items' => Schema::hasTable( 'zash_dine_order_items' ),
                'zash_dine_modifier_groups' => Schema::hasTable( 'zash_dine_modifier_groups' ),
                'zash_dine_modifiers' => Schema::hasTable( 'zash_dine_modifiers' ),
                'zash_dine_product_modifier_groups' => Schema::hasTable( 'zash_dine_product_modifier_groups' ),
                'zash_dine_order_product_modifiers' => Schema::hasTable( 'zash_dine_order_product_modifiers' ),
            ];

            $assets = [
                'pos-script' => is_file( $modulePath . '/Resources/Views/pos-script.blade.php' ),
                'popups-script' => is_file( $modulePath . '/Resources/Views/popups-script.blade.php' ),
                'kitchen-script' => is_file( $modulePath . '/Resources/Views/kitchen-script.blade.php' ),
                'floor-script' => is_file( $modulePath . '/Resources/Views/floor-script.blade.php' ),
                'public-index' => is_file( $modulePath . '/Public/index.html' ),
                'manifest' => is_file( $modulePath . '/manifest.json' ),
                'config' => is_file( $modulePath . '/config.xml' ),
            ];

            $disable = $modules->disable( 'ZashDine' );
            $enableAgain = $modules->enable( 'ZashDine' );

            $report['verification'] = [
                'enabled' => (bool) ( $module['enabled'] ?? false ),
                'version' => $module['version'] ?? null,
                'core_min_version' => $module['core']['min-version'] ?? null,
                'permissions' => $permissions,
                'tables' => $tables,
                'assets' => $assets,
                'disable_status' => $disable['status'] ?? null,
                'enable_again_status' => is_array( $enableAgain ) ? ( $enableAgain['status'] ?? null ) : 'error',
            ];

            $missingPerms = array_values( array_diff(
                ['zash.dine.manage', 'zash.dine.waiter', 'zash.dine.chef'],
                $permissions
            ) );
            $missingTables = array_keys( array_filter( $tables, fn ( $ok ) => ! $ok ) );
            $missingAssets = array_keys( array_filter( $assets, fn ( $ok ) => ! $ok ) );

            if ( $missingPerms !== [] || $missingTables !== [] || $missingAssets !== [] ) {
                throw new \RuntimeException( json_encode( [
                    'missing_permissions' => $missingPerms,
                    'missing_tables' => $missingTables,
                    'missing_assets' => $missingAssets,
                ], JSON_PRETTY_PRINT ) );
            }

            $report['status'] = 'success';
            $report['finished_at'] = now()->toIso8601String();
            File::put( $evidence, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

            // Client ZIP excludes Tests — restore them for the working tree.
            if ( is_dir( $backupPath . DIRECTORY_SEPARATOR . 'Tests' ) ) {
                File::copyDirectory(
                    $backupPath . DIRECTORY_SEPARATOR . 'Tests',
                    $modulePath . DIRECTORY_SEPARATOR . 'Tests'
                );
            }

            if ( is_dir( $backupPath ) ) {
                File::deleteDirectory( $backupPath );
            }

            $this->info( 'Package install validation passed.' );
            $this->line( 'Evidence: ' . $evidence );

            return self::SUCCESS;
        } catch ( \Throwable $e ) {
            $report['status'] = 'failed';
            $report['error'] = $e->getMessage();
            $report['finished_at'] = now()->toIso8601String();
            File::put( $evidence, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

            if ( ! is_dir( $modulePath ) && is_dir( $backupPath ) ) {
                File::copyDirectory( $backupPath, $modulePath );
                File::deleteDirectory( $backupPath );
                $this->warn( 'Restored modules/ZashDine from backup after failure.' );
            } elseif ( ! $this->option( 'keep-backup' ) && is_dir( $backupPath ) ) {
                File::deleteDirectory( $backupPath );
            }

            $this->error( $e->getMessage() );
            $this->line( 'Evidence: ' . $evidence );

            return self::FAILURE;
        }
    }

    private function forgetModule( ModulesService $modules, string $namespace ): void
    {
        $reflection = new \ReflectionClass( $modules );
        $property = $reflection->getProperty( 'modules' );
        $property->setAccessible( true );
        $all = $property->getValue( $modules );

        if ( is_array( $all ) ) {
            unset( $all[$namespace] );
            $property->setValue( $modules, $all );
        }
    }
}

<?php

/**
 * ZashDine permissions migration.
 */

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * @var array<string, array{name: string, description: string}>
     */
    private const PERMISSIONS = [
        'zash.dine.manage' => [
            'name' => 'Manage ZashDine',
            'description' => 'Full access to ZashDine restaurant configuration and settings.',
        ],
        'zash.dine.waiter' => [
            'name' => 'ZashDine Waiter',
            'description' => 'Allow waiters to manage dine-in tables, notes, and meal cancellations.',
        ],
        'zash.dine.chef' => [
            'name' => 'ZashDine Chef',
            'description' => 'Allow kitchen staff to update item preparation status.',
        ],
    ];

    public function up(): void
    {
        $namespaces = [];

        foreach ( self::PERMISSIONS as $namespace => $meta ) {
            $existingByNamespace = Permission::where( 'namespace', $namespace )->first();
            $existingByName = Permission::where( 'name', $meta['name'] )
                ->where( 'namespace', '!=', $namespace )
                ->first();

            if ( $existingByName ) {
                throw new RuntimeException(
                    sprintf(
                        'Cannot create permission "%s": name "%s" is already used by namespace "%s".',
                        $namespace,
                        $meta['name'],
                        $existingByName->namespace
                    )
                );
            }

            $permission = $existingByNamespace ?? Permission::firstOrNew( ['namespace' => $namespace] );
            $permission->namespace = $namespace;
            $permission->name = $meta['name'];
            $permission->description = $meta['description'];
            $permission->save();

            $namespaces[] = $namespace;
        }

        $admin = Role::namespace( Role::ADMIN );

        if ( $admin ) {
            $admin->addPermissions( $namespaces );
        }

        $storeAdmin = Role::namespace( Role::STOREADMIN );

        if ( $storeAdmin ) {
            $storeAdmin->addPermissions( $namespaces );
        }
    }

    public function down(): void
    {
        $namespaces = array_keys( self::PERMISSIONS );

        Role::whereIn( 'namespace', [Role::ADMIN, Role::STOREADMIN] )
            ->get()
            ->each( function ( Role $role ) use ( $namespaces ): void {
                $role->removePermissions( $namespaces );
            } );

        Permission::whereIn( 'namespace', $namespaces )->get()->each( function ( Permission $permission ): void {
            $permission->removeFromRoles();
            $permission->delete();
        } );
    }
};

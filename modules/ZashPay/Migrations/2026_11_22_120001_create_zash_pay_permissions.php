<?php

/**
 * ZashPay permissions migration.
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
        'zash.pay.manage' => [
            'name' => 'Manage ZashPay',
            'description' => 'Configure ZashPay integrated PDQ settings and reconcile terminal attempts.',
        ],
        'zash.pay.charge' => [
            'name' => 'Charge ZashPay Terminal',
            'description' => 'Start and poll integrated PDQ terminal checkouts from the POS.',
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

        foreach ( [Role::ADMIN, Role::STOREADMIN] as $roleNamespace ) {
            $role = Role::namespace( $roleNamespace );

            if ( $role ) {
                $role->addPermissions( $namespaces );
            }
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

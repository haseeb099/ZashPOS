<?php

/**
 * Explicit refund permission for ZashPay terminal refunds.
 */

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const NAMESPACE = 'zash.pay.refund';

    private const NAME = 'Refund ZashPay Terminal';

    private const DESCRIPTION = 'Record integrated PDQ terminal refunds (when accounting refunds are enabled).';

    public function up(): void
    {
        $existingByNamespace = Permission::where( 'namespace', self::NAMESPACE )->first();
        $existingByName = Permission::where( 'name', self::NAME )
            ->where( 'namespace', '!=', self::NAMESPACE )
            ->first();

        if ( $existingByName ) {
            throw new RuntimeException(
                sprintf(
                    'Cannot create permission "%s": name "%s" is already used by namespace "%s".',
                    self::NAMESPACE,
                    self::NAME,
                    $existingByName->namespace
                )
            );
        }

        $permission = $existingByNamespace ?? Permission::firstOrNew( ['namespace' => self::NAMESPACE] );
        $permission->namespace = self::NAMESPACE;
        $permission->name = self::NAME;
        $permission->description = self::DESCRIPTION;
        $permission->save();

        foreach ( [Role::ADMIN, Role::STOREADMIN] as $roleNamespace ) {
            $role = Role::namespace( $roleNamespace );

            if ( $role ) {
                $role->addPermissions( [self::NAMESPACE] );
            }
        }
    }

    public function down(): void
    {
        Role::whereIn( 'namespace', [Role::ADMIN, Role::STOREADMIN] )
            ->get()
            ->each( function ( Role $role ): void {
                $role->removePermissions( [self::NAMESPACE] );
            } );

        $permission = Permission::where( 'namespace', self::NAMESPACE )->first();

        if ( $permission ) {
            $permission->removeFromRoles();
            $permission->delete();
        }
    }
};

<?php

namespace Modules\ZashDine\Crud;

use App\Classes\CrudTable;
use App\Exceptions\NotAllowedException;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use Illuminate\Http\Request;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use TorMorten\Eventy\Facades\Events as Hook;

class DiningTableCrud extends CrudService
{
    const AUTOLOAD = true;

    const IDENTIFIER = 'zashdine.tables';

    protected $table = 'zash_dine_tables';

    protected $namespace = 'zashdine.tables';

    protected $model = DiningTable::class;

    public $relations = [
        ['nexopos_users', 'zash_dine_tables.author_id', '=', 'nexopos_users.id'],
        'leftJoin' => [
            ['zash_dine_areas as area', 'area.id', '=', 'zash_dine_tables.area_id'],
        ],
    ];

    public $pick = [
        'nexopos_users' => ['username'],
        'area' => ['name'],
    ];

    protected $permissions = [
        'create' => 'zash.dine.manage',
        'read' => 'zash.dine.manage',
        'update' => 'zash.dine.manage',
        'delete' => 'zash.dine.manage',
    ];

    public function getLabels()
    {
        return CrudTable::labels(
            list_title: __m( 'Tables List', 'ZashDine' ),
            list_description: __m( 'Display all dining tables.', 'ZashDine' ),
            no_entry: __m( 'No dining table has been registered', 'ZashDine' ),
            create_new: __m( 'Add a new table', 'ZashDine' ),
            create_title: __m( 'Create a new table', 'ZashDine' ),
            create_description: __m( 'Register a table inside a dining area.', 'ZashDine' ),
            edit_title: __m( 'Edit table', 'ZashDine' ),
            edit_description: __m( 'Modify an existing dining table.', 'ZashDine' ),
            back_to_list: __m( 'Return to Tables', 'ZashDine' ),
        );
    }

    public function getForm( $entry = null )
    {
        return [
            'main' => [
                'label' => __m( 'Name', 'ZashDine' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'description' => __m( 'Table label shown on the floor (for example T1). Must be unique within its area.', 'ZashDine' ),
                'validation' => 'required',
            ],
            'tabs' => [
                'general' => [
                    'label' => __m( 'General', 'ZashDine' ),
                    'fields' => [
                        [
                            'type' => 'select',
                            'name' => 'area_id',
                            'label' => __m( 'Area', 'ZashDine' ),
                            'value' => $entry->area_id ?? '',
                            'options' => Helper::toJsOptions(
                                Area::query()->orderBy( 'name' )->get(),
                                ['id', 'name']
                            ),
                            'description' => __m( 'Dining area this table belongs to.', 'ZashDine' ),
                            'validation' => 'required',
                        ],
                        [
                            'type' => 'number',
                            'name' => 'seats',
                            'label' => __m( 'Seats', 'ZashDine' ),
                            'value' => $entry->seats ?? 2,
                            'description' => __m( 'How many guests this table can seat.', 'ZashDine' ),
                            'validation' => 'required|min:1',
                        ],
                        [
                            'type' => 'select',
                            'name' => 'status',
                            'label' => __m( 'Status', 'ZashDine' ),
                            'value' => $entry->status ?? DiningTable::STATUS_AVAILABLE,
                            'options' => Helper::kvToJsOptions( [
                                DiningTable::STATUS_AVAILABLE => __m( 'Available', 'ZashDine' ),
                                DiningTable::STATUS_OCCUPIED => __m( 'Occupied', 'ZashDine' ),
                            ] ),
                            'description' => __m( 'Current occupancy. Occupied tables cannot be deleted.', 'ZashDine' ),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function filterPostInputs( $inputs )
    {
        $inputs['seats'] = max( 1, (int) ( $inputs['seats'] ?? 2 ) );
        $inputs['status'] = $inputs['status'] ?: DiningTable::STATUS_AVAILABLE;
        $inputs['area_id'] = $inputs['area_id'] ?: null;

        return $inputs;
    }

    public function filterPutInputs( $inputs, DiningTable $entry )
    {
        $inputs['seats'] = max( 1, (int) ( $inputs['seats'] ?? $entry->seats ?? 2 ) );
        $inputs['status'] = $inputs['status'] ?: DiningTable::STATUS_AVAILABLE;
        $inputs['area_id'] = $inputs['area_id'] ?: null;

        return $inputs;
    }

    public function beforePost( $request )
    {
        return $request;
    }

    public function afterPost( $request, DiningTable $entry )
    {
        return $request;
    }

    public function beforePut( $request, $entry )
    {
        return $request;
    }

    public function afterPut( $request, $entry )
    {
        return $request;
    }

    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace !== self::IDENTIFIER ) {
            return;
        }

        if ( $model instanceof DiningTable && $model->isOccupied() ) {
            throw new NotAllowedException( __m( 'Unable to delete a table that has an active order.', 'ZashDine' ) );
        }
    }

    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column( __m( 'Name', 'ZashDine' ), 'name' ),
            CrudTable::column( __m( 'Area', 'ZashDine' ), 'area_name' ),
            CrudTable::column( __m( 'Seats', 'ZashDine' ), 'seats' ),
            CrudTable::column( __m( 'Status', 'ZashDine' ), 'status' ),
            CrudTable::column( __m( 'Author', 'ZashDine' ), 'nexopos_users_username' ),
            CrudTable::column( __m( 'Created On', 'ZashDine' ), 'created_at' ),
        );
    }

    public function setActions( CrudEntry $entry ): CrudEntry
    {
        $entry->area_name = $entry->area_name ?: __m( 'N/A', 'ZashDine' );

        $entry->action(
            identifier: 'edit',
            label: __m( 'Edit', 'ZashDine' ),
            type: 'GOTO',
            url: ns()->url( 'dashboard/zash-dine/tables/edit/' . $entry->id )
        );

        $entry->action(
            identifier: 'delete',
            label: __m( 'Delete', 'ZashDine' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/' . self::IDENTIFIER . '/' . $entry->id ),
            confirm: [
                'message' => __m( 'Would you like to delete this table?', 'ZashDine' ),
            ]
        );

        return $entry;
    }

    public function bulkAction( Request $request )
    {
        if ( $request->input( 'action' ) !== 'delete_selected' ) {
            return Hook::filter( $this->namespace . '-catch-action', false, $request );
        }

        $this->allowedTo( 'delete' );

        $status = [
            'success' => 0,
            'error' => 0,
        ];

        foreach ( $request->input( 'entries', [] ) as $id ) {
            $entity = $this->model::find( $id );

            if ( ! $entity instanceof DiningTable ) {
                $status['error']++;

                continue;
            }

            try {
                $this->beforeDelete( self::IDENTIFIER, $id, $entity );
                $entity->delete();
                $status['success']++;
            } catch ( NotAllowedException ) {
                $status['error']++;
            }
        }

        return $status;
    }

    public function getLinks(): array
    {
        return [
            'list' => ns()->url( 'dashboard/zash-dine/tables' ),
            'create' => ns()->url( 'dashboard/zash-dine/tables/create' ),
            'edit' => ns()->url( 'dashboard/zash-dine/tables/edit' ),
            'post' => ns()->url( 'api/crud/' . self::IDENTIFIER ),
            'put' => ns()->url( 'api/crud/' . self::IDENTIFIER . '/{id}' ),
        ];
    }

    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [
            [
                'label' => __m( 'Delete Selected Tables', 'ZashDine' ),
                'identifier' => 'delete_selected',
                'url' => ns()->route( 'ns.api.crud-bulk-actions', [
                    'namespace' => $this->namespace,
                ] ),
            ],
        ] );
    }

    public function getExports()
    {
        return [];
    }

    public function hook( $query ): void
    {
        $query->orderBy( 'name', 'asc' );
    }
}

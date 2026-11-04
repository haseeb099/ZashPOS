<?php

namespace Modules\ZashDine\Crud;

use App\Classes\CrudTable;
use App\Exceptions\NotAllowedException;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use Illuminate\Http\Request;
use Modules\ZashDine\Models\Area;
use TorMorten\Eventy\Facades\Events as Hook;

class AreaCrud extends CrudService
{
    const AUTOLOAD = true;

    const IDENTIFIER = 'zashdine.areas';

    protected $table = 'zash_dine_areas';

    protected $namespace = 'zashdine.areas';

    protected $model = Area::class;

    public $relations = [
        ['nexopos_users', 'zash_dine_areas.author_id', '=', 'nexopos_users.id'],
    ];

    public $pick = [
        'nexopos_users' => ['username'],
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
            list_title: __m( 'Areas List', 'ZashDine' ),
            list_description: __m( 'Display all dining areas.', 'ZashDine' ),
            no_entry: __m( 'No dining area has been registered', 'ZashDine' ),
            create_new: __m( 'Add a new area', 'ZashDine' ),
            create_title: __m( 'Create a new area', 'ZashDine' ),
            create_description: __m( 'Register a dining area for table layout.', 'ZashDine' ),
            edit_title: __m( 'Edit area', 'ZashDine' ),
            edit_description: __m( 'Modify an existing dining area.', 'ZashDine' ),
            back_to_list: __m( 'Return to Areas', 'ZashDine' ),
        );
    }

    public function getForm( $entry = null )
    {
        return [
            'main' => [
                'label' => __m( 'Name', 'ZashDine' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'description' => __m( 'Display name for this dining area (for example Patio or Main Hall).', 'ZashDine' ),
                'validation' => 'required',
            ],
            'tabs' => [
                'general' => [
                    'label' => __m( 'General', 'ZashDine' ),
                    'fields' => [
                        [
                            'type' => 'textarea',
                            'name' => 'description',
                            'label' => __m( 'Description', 'ZashDine' ),
                            'value' => $entry->description ?? '',
                            'description' => __m( 'Optional notes about where this area is and how it is used.', 'ZashDine' ),
                        ],
                        [
                            'type' => 'select',
                            'name' => 'status',
                            'label' => __m( 'Status', 'ZashDine' ),
                            'value' => $entry->status ?? Area::STATUS_ENABLED,
                            'options' => Helper::kvToJsOptions( [
                                Area::STATUS_ENABLED => __m( 'Enabled', 'ZashDine' ),
                                Area::STATUS_DISABLED => __m( 'Disabled', 'ZashDine' ),
                            ] ),
                            'description' => __m( 'Disabled areas stay in the list but are hidden from the floor layout.', 'ZashDine' ),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function filterPostInputs( $inputs )
    {
        $inputs['status'] = $inputs['status'] ?: Area::STATUS_ENABLED;

        return $inputs;
    }

    public function filterPutInputs( $inputs, Area $entry )
    {
        $inputs['status'] = $inputs['status'] ?: Area::STATUS_ENABLED;

        return $inputs;
    }

    public function beforePost( $request )
    {
        return $request;
    }

    public function afterPost( $request, Area $entry )
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

        if ( $model->tables()->exists() ) {
            throw new NotAllowedException( __m( 'Unable to delete an area that still has tables.', 'ZashDine' ) );
        }
    }

    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column( __m( 'Name', 'ZashDine' ), 'name' ),
            CrudTable::column( __m( 'Status', 'ZashDine' ), 'status' ),
            CrudTable::column( __m( 'Author', 'ZashDine' ), 'nexopos_users_username' ),
            CrudTable::column( __m( 'Created On', 'ZashDine' ), 'created_at' ),
        );
    }

    public function setActions( CrudEntry $entry ): CrudEntry
    {
        $entry->action(
            identifier: 'edit',
            label: __m( 'Edit', 'ZashDine' ),
            type: 'GOTO',
            url: ns()->url( 'dashboard/zash-dine/areas/edit/' . $entry->id )
        );

        $entry->action(
            identifier: 'delete',
            label: __m( 'Delete', 'ZashDine' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/' . self::IDENTIFIER . '/' . $entry->id ),
            confirm: [
                'message' => __m( 'Would you like to delete this area?', 'ZashDine' ),
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

            if ( ! $entity instanceof Area ) {
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
            'list' => ns()->url( 'dashboard/zash-dine/areas' ),
            'create' => ns()->url( 'dashboard/zash-dine/areas/create' ),
            'edit' => ns()->url( 'dashboard/zash-dine/areas/edit' ),
            'post' => ns()->url( 'api/crud/' . self::IDENTIFIER ),
            'put' => ns()->url( 'api/crud/' . self::IDENTIFIER . '/{id}' ),
        ];
    }

    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [
            [
                'label' => __m( 'Delete Selected Areas', 'ZashDine' ),
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

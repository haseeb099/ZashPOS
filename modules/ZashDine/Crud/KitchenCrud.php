<?php

namespace Modules\ZashDine\Crud;

use App\Classes\CrudTable;
use App\Exceptions\NotAllowedException;
use App\Models\ProductCategory;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\ZashDine\Models\Kitchen;
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Services\KitchenService;
use TorMorten\Eventy\Facades\Events as Hook;

class KitchenCrud extends CrudService
{
    const AUTOLOAD = true;

    const IDENTIFIER = 'zashdine.kitchens';

    protected $table = 'zash_dine_kitchens';

    protected $namespace = 'zashdine.kitchens';

    protected $model = Kitchen::class;

    public $relations = [
        ['nexopos_users', 'zash_dine_kitchens.author_id', '=', 'nexopos_users.id'],
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

    /**
     * Persist kitchen + category pivot atomically so a conflict rolls back the kitchen row.
     */
    public bool $wrapSubmitInTransaction = true;

    public function getLabels()
    {
        return CrudTable::labels(
            list_title: __m( 'Kitchens List', 'ZashDine' ),
            list_description: __m( 'Display all kitchens and their category routing.', 'ZashDine' ),
            no_entry: __m( 'No kitchen has been registered', 'ZashDine' ),
            create_new: __m( 'Add a new kitchen', 'ZashDine' ),
            create_title: __m( 'Create a new kitchen', 'ZashDine' ),
            create_description: __m( 'Register a kitchen and assign product categories.', 'ZashDine' ),
            edit_title: __m( 'Edit kitchen', 'ZashDine' ),
            edit_description: __m( 'Modify an existing kitchen.', 'ZashDine' ),
            back_to_list: __m( 'Return to Kitchens', 'ZashDine' ),
        );
    }

    public function getForm( $entry = null )
    {
        $selectedCategories = [];

        if ( $entry instanceof Kitchen ) {
            $selectedCategories = $entry->categories()->pluck( 'nexopos_products_categories.id' )->map( fn ( $id ) => (int) $id )->all();
        }

        return [
            'main' => [
                'label' => __m( 'Name', 'ZashDine' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'description' => __m( 'Display name for this kitchen (for example Grill or Bar).', 'ZashDine' ),
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
                            'description' => __m( 'Optional notes about this kitchen station.', 'ZashDine' ),
                        ],
                        [
                            'type' => 'select',
                            'name' => 'status',
                            'label' => __m( 'Status', 'ZashDine' ),
                            'value' => $entry->status ?? Kitchen::STATUS_ENABLED,
                            'options' => Helper::kvToJsOptions( [
                                Kitchen::STATUS_ENABLED => __m( 'Enabled', 'ZashDine' ),
                                Kitchen::STATUS_DISABLED => __m( 'Disabled', 'ZashDine' ),
                            ] ),
                            'description' => __m( 'Disabled kitchens stop receiving newly routed meals.', 'ZashDine' ),
                        ],
                        [
                            'type' => 'multiselect',
                            'name' => 'category_ids',
                            'label' => __m( 'Product categories', 'ZashDine' ),
                            'value' => $selectedCategories,
                            'options' => Helper::toJsOptions( ProductCategory::query()->orderBy( 'name' )->get(), ['id', 'name'] ),
                            'description' => __m( 'Each category can belong to only one kitchen. Unassigned categories stay visible in the kitchen Unassigned queue.', 'ZashDine' ),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function filterPostInputs( $inputs )
    {
        $inputs['status'] = $inputs['status'] ?: Kitchen::STATUS_ENABLED;
        $inputs['author_id'] = Auth::id();
        unset( $inputs['category_ids'] );

        return $inputs;
    }

    public function filterPutInputs( $inputs, Kitchen $entry )
    {
        $inputs['status'] = $inputs['status'] ?: Kitchen::STATUS_ENABLED;
        unset( $inputs['category_ids'] );

        return $inputs;
    }

    public function beforePost( $request, $entry = null, $inputs = [] )
    {
        app( KitchenService::class )->assertCategoryAssignmentAllowed(
            null,
            $this->categoryIdsFromRequest( $request )
        );
    }

    public function beforePut( $request, Kitchen $entry, $inputs = [] )
    {
        app( KitchenService::class )->assertCategoryAssignmentAllowed(
            $entry,
            $this->categoryIdsFromRequest( $request )
        );
    }

    public function afterPost( $request, Kitchen $entry )
    {
        $this->syncCategoriesFromRequest( $request, $entry );

        return $request;
    }

    public function afterPut( $request, Kitchen $entry )
    {
        $this->syncCategoriesFromRequest( $request, $entry );

        return $request;
    }

    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace !== self::IDENTIFIER ) {
            return;
        }

        if ( $model instanceof Kitchen && $model->orderItems()
            ->whereIn( 'status', [OrderItem::STATUS_PENDING, OrderItem::STATUS_PREPARING] )
            ->exists() ) {
            throw new NotAllowedException(
                __m( 'Unable to delete a kitchen that still has pending or preparing meals.', 'ZashDine' )
            );
        }

        if ( $model instanceof Kitchen ) {
            $model->categories()->detach();
        }
    }

    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column( __m( 'Name', 'ZashDine' ), 'name' ),
            CrudTable::column( __m( 'Status', 'ZashDine' ), 'status' ),
            CrudTable::column( __m( 'Categories', 'ZashDine' ), 'categories_list' ),
            CrudTable::column( __m( 'Author', 'ZashDine' ), 'nexopos_users_username' ),
            CrudTable::column( __m( 'Created On', 'ZashDine' ), 'created_at' ),
        );
    }

    public function setActions( CrudEntry $entry ): CrudEntry
    {
        $kitchen = Kitchen::query()->with( 'categories:id,name' )->find( $entry->id );
        $entry->categories_list = $kitchen
            ? $kitchen->categories->pluck( 'name' )->join( ', ' ) ?: __m( 'None', 'ZashDine' )
            : __m( 'None', 'ZashDine' );

        $entry->action(
            identifier: 'edit',
            label: __m( 'Edit', 'ZashDine' ),
            type: 'GOTO',
            url: ns()->url( 'dashboard/zash-dine/kitchens/edit/' . $entry->id )
        );

        $entry->action(
            identifier: 'delete',
            label: __m( 'Delete', 'ZashDine' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/' . self::IDENTIFIER . '/' . $entry->id ),
            confirm: [
                'message' => __m( 'Would you like to delete this kitchen?', 'ZashDine' ),
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

            if ( ! $entity instanceof Kitchen ) {
                $status['error']++;

                continue;
            }

            try {
                $this->beforeDelete( self::IDENTIFIER, $id, $entity );
                $entity->categories()->detach();
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
            'list' => ns()->url( 'dashboard/zash-dine/kitchens' ),
            'create' => ns()->url( 'dashboard/zash-dine/kitchens/create' ),
            'edit' => ns()->url( 'dashboard/zash-dine/kitchens/edit' ),
            'post' => ns()->url( 'api/crud/' . self::IDENTIFIER ),
            'put' => ns()->url( 'api/crud/' . self::IDENTIFIER . '/{id}' ),
        ];
    }

    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [
            [
                'label' => __m( 'Delete Selected Kitchens', 'ZashDine' ),
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

    private function syncCategoriesFromRequest( $request, Kitchen $entry ): void
    {
        app( KitchenService::class )->syncKitchenCategories(
            $entry,
            $this->categoryIdsFromRequest( $request )
        );
    }

    /**
     * @return list<int|string>
     */
    private function categoryIdsFromRequest( $request ): array
    {
        $categoryIds = [];

        if ( is_array( $request ) ) {
            $categoryIds = $request['category_ids']
                ?? $request['general']['category_ids']
                ?? [];
        } elseif ( is_object( $request ) && method_exists( $request, 'input' ) ) {
            $categoryIds = $request->input( 'category_ids', $request->input( 'general.category_ids', [] ) );
        }

        return is_array( $categoryIds ) ? $categoryIds : [];
    }
}

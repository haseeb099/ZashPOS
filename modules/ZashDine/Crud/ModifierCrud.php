<?php

namespace Modules\ZashDine\Crud;

use App\Classes\CrudTable;
use App\Exceptions\NotAllowedException;
use App\Models\Product;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\ZashDine\Models\Modifier;
use Modules\ZashDine\Models\ModifierGroup;
use TorMorten\Eventy\Facades\Events as Hook;

class ModifierCrud extends CrudService
{
    const AUTOLOAD = true;

    const IDENTIFIER = 'zashdine.modifiers';

    protected $table = 'zash_dine_modifiers';

    protected $namespace = 'zashdine.modifiers';

    protected $model = Modifier::class;

    public $relations = [
        ['nexopos_users', 'zash_dine_modifiers.author_id', '=', 'nexopos_users.id'],
        ['zash_dine_modifier_groups', 'zash_dine_modifiers.modifier_group_id', '=', 'zash_dine_modifier_groups.id'],
    ];

    public $pick = [
        'nexopos_users' => ['username'],
        'zash_dine_modifier_groups' => ['name'],
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
            list_title: __m( 'Modifiers List', 'ZashDine' ),
            list_description: __m( 'Display modifiers, their prices, and optional stock ingredients.', 'ZashDine' ),
            no_entry: __m( 'No modifier has been registered', 'ZashDine' ),
            create_new: __m( 'Add a new modifier', 'ZashDine' ),
            create_title: __m( 'Create a new modifier', 'ZashDine' ),
            create_description: __m( 'Add an option that can be chosen inside a modifier group.', 'ZashDine' ),
            edit_title: __m( 'Edit modifier', 'ZashDine' ),
            edit_description: __m( 'Modify an existing modifier.', 'ZashDine' ),
            back_to_list: __m( 'Return to Modifiers', 'ZashDine' ),
        );
    }

    public function getForm( $entry = null )
    {
        return [
            'main' => [
                'label' => __m( 'Name', 'ZashDine' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'description' => __m( 'Name shown on the POS and on the kitchen ticket.', 'ZashDine' ),
                'validation' => 'required',
            ],
            'tabs' => [
                'general' => [
                    'label' => __m( 'General', 'ZashDine' ),
                    'fields' => [
                        [
                            'type' => 'select',
                            'name' => 'modifier_group_id',
                            'label' => __m( 'Modifier group', 'ZashDine' ),
                            'value' => $entry->modifier_group_id ?? '',
                            'options' => Helper::toJsOptions(
                                ModifierGroup::query()->orderBy( 'name' )->get(),
                                ['id', 'name']
                            ),
                            'description' => __m( 'The group that controls whether this modifier is required, limited, or sold in quantity.', 'ZashDine' ),
                            'validation' => 'required',
                        ],
                        [
                            'type' => 'number',
                            'name' => 'price',
                            'label' => __m( 'Price', 'ZashDine' ),
                            'value' => $entry->price ?? 0,
                            'description' => __m( 'Surcharge added to the product unit price for each unit of this modifier. Use 0 when the option is free.', 'ZashDine' ),
                        ],
                        [
                            'type' => 'select',
                            'name' => 'product_id',
                            'label' => __m( 'Ingredient', 'ZashDine' ),
                            'value' => $entry->product_id ?? '',
                            'options' => Helper::toJsOptions(
                                Product::query()->orderBy( 'name' )->get( ['id', 'name'] ),
                                ['id', 'name'],
                                ['' => __m( 'No ingredient', 'ZashDine' )]
                            ),
                            'description' => __m( 'Optional stock item deducted when this modifier is sold. Quantity used is modifier quantity times meal quantity.', 'ZashDine' ),
                        ],
                        [
                            'type' => 'select',
                            'name' => 'status',
                            'label' => __m( 'Status', 'ZashDine' ),
                            'value' => $entry->status ?? Modifier::STATUS_ENABLED,
                            'options' => Helper::kvToJsOptions( [
                                Modifier::STATUS_ENABLED => __m( 'Enabled', 'ZashDine' ),
                                Modifier::STATUS_DISABLED => __m( 'Disabled', 'ZashDine' ),
                            ] ),
                            'description' => __m( 'Disabled modifiers stay in the list but cannot be added to new orders.', 'ZashDine' ),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function filterPostInputs( $inputs )
    {
        return $this->normalizeInputs( $inputs, true );
    }

    public function filterPutInputs( $inputs, Modifier $entry )
    {
        return $this->normalizeInputs( $inputs, false );
    }

    public function beforePost( $request, $entry = null, $inputs = [] )
    {
        $this->assertReferences( is_array( $inputs ) ? $inputs : [] );
    }

    public function beforePut( $request, Modifier $entry, $inputs = [] )
    {
        $this->assertReferences( is_array( $inputs ) ? $inputs : [] );
    }

    public function afterPost( $request, Modifier $entry )
    {
        return $request;
    }

    public function afterPut( $request, Modifier $entry )
    {
        return $request;
    }

    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace !== self::IDENTIFIER ) {
            return;
        }
    }

    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column( __m( 'Name', 'ZashDine' ), 'name' ),
            CrudTable::column( __m( 'Group', 'ZashDine' ), 'zash_dine_modifier_groups_name' ),
            CrudTable::column( __m( 'Price', 'ZashDine' ), 'price' ),
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
            url: ns()->url( 'dashboard/zash-dine/modifiers/edit/' . $entry->id )
        );

        $entry->action(
            identifier: 'delete',
            label: __m( 'Delete', 'ZashDine' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/' . self::IDENTIFIER . '/' . $entry->id ),
            confirm: [
                'message' => __m( 'Would you like to delete this modifier?', 'ZashDine' ),
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

            if ( ! $entity instanceof Modifier ) {
                $status['error']++;

                continue;
            }

            $entity->delete();
            $status['success']++;
        }

        return $status;
    }

    public function getLinks(): array
    {
        return [
            'list' => ns()->url( 'dashboard/zash-dine/modifiers' ),
            'create' => ns()->url( 'dashboard/zash-dine/modifiers/create' ),
            'edit' => ns()->url( 'dashboard/zash-dine/modifiers/edit' ),
            'post' => ns()->url( 'api/crud/' . self::IDENTIFIER ),
            'put' => ns()->url( 'api/crud/' . self::IDENTIFIER . '/{id}' ),
        ];
    }

    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [
            [
                'label' => __m( 'Delete Selected Modifiers', 'ZashDine' ),
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

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    protected function normalizeInputs( array $inputs, bool $creating ): array
    {
        $inputs['price'] = is_numeric( $inputs['price'] ?? null ) ? (float) $inputs['price'] : 0;
        $inputs['product_id'] = ( $inputs['product_id'] ?? '' ) === '' ? null : (int) $inputs['product_id'];
        $inputs['status'] = $inputs['status'] ?: Modifier::STATUS_ENABLED;
        $inputs['modifier_group_id'] = (int) ( $inputs['modifier_group_id'] ?? 0 );

        if ( $creating ) {
            $inputs['author_id'] = Auth::id();
        }

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function assertReferences( array $inputs ): void
    {
        $groupId = (int) ( $inputs['modifier_group_id'] ?? 0 );

        if ( ! ModifierGroup::query()->whereKey( $groupId )->exists() ) {
            throw new NotAllowedException( __m( 'Choose a modifier group that exists.', 'ZashDine' ) );
        }

        $productId = $inputs['product_id'] ?? null;

        if ( $productId && ! Product::query()->whereKey( (int) $productId )->exists() ) {
            throw new NotAllowedException( __m( 'The modifier ingredient could not be found.', 'ZashDine' ) );
        }

        if ( ! in_array( $inputs['status'] ?? '', [Modifier::STATUS_ENABLED, Modifier::STATUS_DISABLED], true ) ) {
            throw new NotAllowedException( __m( 'Choose whether this modifier is enabled or disabled.', 'ZashDine' ) );
        }
    }
}

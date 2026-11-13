<?php

namespace Modules\ZashDine\Crud;

use App\Classes\CrudTable;
use App\Exceptions\NotAllowedException;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\ZashDine\Models\ModifierGroup;
use TorMorten\Eventy\Facades\Events as Hook;

class ModifierGroupCrud extends CrudService
{
    const AUTOLOAD = true;

    const IDENTIFIER = 'zashdine.modifier-groups';

    protected $table = 'zash_dine_modifier_groups';

    protected $namespace = 'zashdine.modifier-groups';

    protected $model = ModifierGroup::class;

    public $relations = [
        ['nexopos_users', 'zash_dine_modifier_groups.author_id', '=', 'nexopos_users.id'],
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
            list_title: __m( 'Modifier Groups List', 'ZashDine' ),
            list_description: __m( 'Display modifier groups that can be assigned to products.', 'ZashDine' ),
            no_entry: __m( 'No modifier group has been registered', 'ZashDine' ),
            create_new: __m( 'Add a new modifier group', 'ZashDine' ),
            create_title: __m( 'Create a new modifier group', 'ZashDine' ),
            create_description: __m( 'Register selection rules for a set of modifiers.', 'ZashDine' ),
            edit_title: __m( 'Edit modifier group', 'ZashDine' ),
            edit_description: __m( 'Modify an existing modifier group.', 'ZashDine' ),
            back_to_list: __m( 'Return to Modifier Groups', 'ZashDine' ),
        );
    }

    public function getForm( $entry = null )
    {
        return [
            'main' => [
                'label' => __m( 'Name', 'ZashDine' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'description' => __m( 'Name shown on the POS when a cashier picks options for this group.', 'ZashDine' ),
                'validation' => 'required',
            ],
            'tabs' => [
                'general' => [
                    'label' => __m( 'General', 'ZashDine' ),
                    'fields' => [
                        [
                            'type' => 'select',
                            'name' => 'selection_type',
                            'label' => __m( 'Selection type', 'ZashDine' ),
                            'value' => $entry->selection_type ?? ModifierGroup::SELECTION_SINGLE,
                            'options' => Helper::kvToJsOptions( [
                                ModifierGroup::SELECTION_SINGLE => __m( 'Single', 'ZashDine' ),
                                ModifierGroup::SELECTION_MULTIPLE => __m( 'Multiple', 'ZashDine' ),
                            ] ),
                            'description' => __m( 'Single allows one modifier. Multiple allows several, still limited by the minimum and maximum.', 'ZashDine' ),
                            'validation' => 'required',
                        ],
                        [
                            'type' => 'number',
                            'name' => 'min_selections',
                            'label' => __m( 'Minimum selections', 'ZashDine' ),
                            'value' => $entry->min_selections ?? 0,
                            'description' => __m( 'How many modifiers must be chosen. Use 0 when the group is optional.', 'ZashDine' ),
                        ],
                        [
                            'type' => 'number',
                            'name' => 'max_selections',
                            'label' => __m( 'Maximum selections', 'ZashDine' ),
                            'value' => $entry->max_selections ?? '',
                            'description' => __m( 'Upper limit for multiple groups. Leave empty for no limit. Single groups never allow more than one.', 'ZashDine' ),
                        ],
                        [
                            'type' => 'select',
                            'name' => 'allow_quantity',
                            'label' => __m( 'Allow quantity', 'ZashDine' ),
                            'value' => ( $entry->allow_quantity ?? false ) ? '1' : '0',
                            'options' => Helper::kvToJsOptions( [
                                '0' => __m( 'No', 'ZashDine' ),
                                '1' => __m( 'Yes', 'ZashDine' ),
                            ] ),
                            'description' => __m( 'When enabled, each chosen modifier can be sold in a quantity greater than one. The surcharge is price times that quantity.', 'ZashDine' ),
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

    public function filterPutInputs( $inputs, ModifierGroup $entry )
    {
        return $this->normalizeInputs( $inputs, false );
    }

    public function beforePost( $request, $entry = null, $inputs = [] )
    {
        $this->assertRules( is_array( $inputs ) ? $inputs : [] );
    }

    public function beforePut( $request, ModifierGroup $entry, $inputs = [] )
    {
        $this->assertRules( is_array( $inputs ) ? $inputs : [] );
    }

    public function afterPost( $request, ModifierGroup $entry )
    {
        return $request;
    }

    public function afterPut( $request, ModifierGroup $entry )
    {
        return $request;
    }

    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace !== self::IDENTIFIER || ! $model instanceof ModifierGroup ) {
            return;
        }

        if ( $model->modifiers()->exists() ) {
            throw new NotAllowedException(
                __m( 'Unable to delete a modifier group that still has modifiers.', 'ZashDine' )
            );
        }

        $model->products()->detach();
    }

    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column( __m( 'Name', 'ZashDine' ), 'name' ),
            CrudTable::column( __m( 'Selection', 'ZashDine' ), 'selection_type' ),
            CrudTable::column( __m( 'Minimum', 'ZashDine' ), 'min_selections' ),
            CrudTable::column( __m( 'Maximum', 'ZashDine' ), 'max_selections' ),
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
            url: ns()->url( 'dashboard/zash-dine/modifier-groups/edit/' . $entry->id )
        );

        $entry->action(
            identifier: 'delete',
            label: __m( 'Delete', 'ZashDine' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/' . self::IDENTIFIER . '/' . $entry->id ),
            confirm: [
                'message' => __m( 'Would you like to delete this modifier group?', 'ZashDine' ),
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

            if ( ! $entity instanceof ModifierGroup ) {
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
            'list' => ns()->url( 'dashboard/zash-dine/modifier-groups' ),
            'create' => ns()->url( 'dashboard/zash-dine/modifier-groups/create' ),
            'edit' => ns()->url( 'dashboard/zash-dine/modifier-groups/edit' ),
            'post' => ns()->url( 'api/crud/' . self::IDENTIFIER ),
            'put' => ns()->url( 'api/crud/' . self::IDENTIFIER . '/{id}' ),
        ];
    }

    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [
            [
                'label' => __m( 'Delete Selected Groups', 'ZashDine' ),
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
        $inputs['selection_type'] = $inputs['selection_type'] ?: ModifierGroup::SELECTION_SINGLE;
        $inputs['min_selections'] = (int) ( $inputs['min_selections'] ?? 0 );
        $max = $inputs['max_selections'] ?? null;
        $inputs['max_selections'] = ( $max === '' || $max === null ) ? null : (int) $max;
        $inputs['allow_quantity'] = filter_var( $inputs['allow_quantity'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( $creating ) {
            $inputs['author_id'] = Auth::id();
        }

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function assertRules( array $inputs ): void
    {
        $type = (string) ( $inputs['selection_type'] ?? '' );

        if ( ! in_array( $type, [ModifierGroup::SELECTION_SINGLE, ModifierGroup::SELECTION_MULTIPLE], true ) ) {
            throw new NotAllowedException( __m( 'Choose either a single or multiple modifier selection.', 'ZashDine' ) );
        }

        $minimum = (int) ( $inputs['min_selections'] ?? 0 );

        if ( $minimum < 0 ) {
            throw new NotAllowedException( __m( 'The minimum number of selections cannot be negative.', 'ZashDine' ) );
        }

        $maximum = $inputs['max_selections'] ?? null;

        if ( $maximum !== null && $maximum !== '' && (int) $maximum < $minimum ) {
            throw new NotAllowedException( __m( 'The maximum number of selections cannot be lower than the minimum.', 'ZashDine' ) );
        }

        if ( $type === ModifierGroup::SELECTION_SINGLE && $minimum > 1 ) {
            throw new NotAllowedException( __m( 'A single-choice group cannot require more than one selection.', 'ZashDine' ) );
        }

        if ( $type === ModifierGroup::SELECTION_SINGLE && $maximum !== null && $maximum !== '' && (int) $maximum > 1 ) {
            throw new NotAllowedException( __m( 'A single-choice group cannot allow more than one selection.', 'ZashDine' ) );
        }
    }
}

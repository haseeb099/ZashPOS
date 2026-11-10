<?php

namespace Tests\Feature;

use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Services\OrdersService;
use Exception;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithCustomerTest;

class PosLoadedOrderCustomerGroupTest extends TestCase
{
    use WithAuthentication, WithCustomerTest;

    public function test_pos_loaded_order_preserves_customer_group_payment_rules(): void
    {
        $this->attemptAuthenticate();

        $group = CustomerGroup::first();

        if ( ! $group instanceof CustomerGroup ) {
            $group = $this->attemptCreateCustomerGroup();
        }

        $originalMinimalPayment = (float) $group->minimal_credit_payment;
        $group->minimal_credit_payment = 35;
        $group->save();

        try {
            $customer = $this->attemptCreateCustomer();
            $customer->group_id = $group->id;
            $customer->save();
            $customer->refresh();

            $this->assertSame( (int) $group->id, (int) $customer->group_id );

            $product = Product::withStockEnabled()
                ->notGrouped()
                ->with( 'unit_quantities' )
                ->first();

            if ( ! $product instanceof Product ) {
                throw new Exception( 'No product is available for the POS order customer-group regression.' );
            }

            $product->unit_quantities->each( function ( $unitQuantity ) {
                $unitQuantity->quantity = 10000;
                $unitQuantity->save();
            } );

            $unitQuantity = $product->unit_quantities->first();

            if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
                throw new Exception( 'No product unit quantity is available for the POS order customer-group regression.' );
            }

            $quantity = 1;
            $subtotal = $unitQuantity->sale_price * $quantity;

            $createResponse = $this->withSession( $this->app[ 'session' ]->all() )
                ->json( 'POST', 'api/orders', [
                    'customer_id' => $customer->id,
                    'type' => [ 'identifier' => 'takeaway' ],
                    'discount_type' => 'percentage',
                    'discount_percentage' => 0,
                    'addresses' => [
                        'shipping' => [
                            'name' => 'POS Load Shipping',
                            'surname' => 'Customer',
                            'country' => 'US',
                        ],
                        'billing' => [
                            'name' => 'POS Load Billing',
                            'surname' => 'Customer',
                            'country' => 'US',
                        ],
                    ],
                    'payment_status' => Order::PAYMENT_UNPAID,
                    'subtotal' => $subtotal,
                    'shipping' => 0,
                    'products' => [
                        [
                            'product_id' => $product->id,
                            'quantity' => $quantity,
                            'unit_price' => $unitQuantity->sale_price,
                            'unit_quantity_id' => $unitQuantity->id,
                        ],
                    ],
                ] );

            $createResponse->assertStatus( 200 );
            $orderId = (int) $createResponse->json( 'data.order.id' );
            $this->assertGreaterThan( 0, $orderId );

            /** @var OrdersService $ordersService */
            $ordersService = app()->make( OrdersService::class );
            $loaded = $ordersService->getOrder( (string) $orderId );

            $this->assertTrue( $loaded->relationLoaded( 'customer' ) );
            $this->assertNotNull( $loaded->customer );
            $this->assertSame( (int) $customer->id, (int) $loaded->customer->id );
            $this->assertTrue( $loaded->customer->relationLoaded( 'group' ) );
            $this->assertNotNull( $loaded->customer->group );
            $this->assertSame( (int) $group->id, (int) $loaded->customer->group->id );
            $this->assertSame( 35.0, (float) $loaded->customer->group->minimal_credit_payment );

            $posResponse = $this->withSession( $this->app[ 'session' ]->all() )
                ->json( 'GET', 'api/pos/orders/' . $orderId );

            $posResponse->assertOk();
            $posResponse->assertJsonPath( 'customer.id', $customer->id );
            $posResponse->assertJsonPath( 'customer.group.id', $group->id );
            $this->assertSame(
                35.0,
                (float) $posResponse->json( 'customer.group.minimal_credit_payment' )
            );

            $holdResponse = $this->withSession( $this->app[ 'session' ]->all() )
                ->json( 'POST', 'api/orders', [
                    'customer_id' => $customer->id,
                    'type' => [ 'identifier' => 'takeaway' ],
                    'discount_type' => 'percentage',
                    'discount_percentage' => 0,
                    'addresses' => [
                        'shipping' => [
                            'name' => 'POS Hold Shipping',
                            'surname' => 'Customer',
                            'country' => 'US',
                        ],
                        'billing' => [
                            'name' => 'POS Hold Billing',
                            'surname' => 'Customer',
                            'country' => 'US',
                        ],
                    ],
                    'payment_status' => Order::PAYMENT_HOLD,
                    'subtotal' => $subtotal,
                    'shipping' => 0,
                    'products' => [
                        [
                            'product_id' => $product->id,
                            'quantity' => $quantity,
                            'unit_price' => $unitQuantity->sale_price,
                            'unit_quantity_id' => $unitQuantity->id,
                        ],
                    ],
                ] );

            $holdResponse->assertStatus( 200 );
            $holdId = (int) $holdResponse->json( 'data.order.id' );

            $heldPosResponse = $this->withSession( $this->app[ 'session' ]->all() )
                ->json( 'GET', 'api/pos/orders/' . $holdId );

            $heldPosResponse->assertOk();
            $heldPosResponse->assertJsonPath( 'customer.id', $customer->id );
            $heldPosResponse->assertJsonPath( 'customer.group.id', $group->id );
            $this->assertSame(
                35.0,
                (float) $heldPosResponse->json( 'customer.group.minimal_credit_payment' )
            );
            $this->assertSame( Order::PAYMENT_HOLD, $heldPosResponse->json( 'payment_status' ) );
        } finally {
            $group->minimal_credit_payment = $originalMinimalPayment;
            $group->save();
        }
    }
}

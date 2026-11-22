<?php

namespace Modules\ZashPay\Tests\Feature;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ZashPay\Models\TerminalAttempt;
use Modules\ZashPay\Services\TerminalPaymentService;
use Modules\ZashPay\Services\ZashPayAddonGate;
use Tests\TestCase;

/**
 * Optional MySQL concurrency probe. Skips unless ZASHPAY_MYSQL_CONCURRENCY=1
 * and the staging MySQL connection answers. Reported separately from SQLite.
 *
 * Uses the service layer (not HTTP) so phpunit.xml sqlite DB_* overrides do not
 * reset the connection between requests.
 */
final class ZashPayMysqlConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ( ! filter_var( env( 'ZASHPAY_MYSQL_CONCURRENCY', false ), FILTER_VALIDATE_BOOLEAN ) ) {
            $this->markTestSkipped( 'MySQL concurrency probe skipped (set ZASHPAY_MYSQL_CONCURRENCY=1).' );
        }

        config( [
            'database.connections.mysql.host' => env( 'ZASHPAY_MYSQL_HOST', '127.0.0.1' ),
            'database.connections.mysql.port' => env( 'ZASHPAY_MYSQL_PORT', '3307' ),
            'database.connections.mysql.database' => env( 'ZASHPAY_MYSQL_DATABASE', 'zashpos_staging' ),
            'database.connections.mysql.username' => env( 'ZASHPAY_MYSQL_USERNAME', 'zashpos' ),
            'database.connections.mysql.password' => env( 'ZASHPAY_MYSQL_PASSWORD', 'zashpos_staging_local' ),
            'database.connections.mysql.prefix' => env( 'ZASHPAY_MYSQL_PREFIX', '' ),
        ] );

        try {
            DB::purge( 'mysql' );
            DB::connection( 'mysql' )->getPdo();
        } catch ( \Throwable $exception ) {
            $this->markTestSkipped( 'MySQL concurrency probe skipped: ' . $exception->getMessage() );
        }

        if ( ! in_array( DB::connection( 'mysql' )->getDriverName(), ['mysql', 'mariadb'], true ) ) {
            $this->markTestSkipped( 'MySQL concurrency probe skipped (driver not mysql/mariadb).' );
        }
    }

    public function test_locked_attempt_prevents_double_settle_under_mysql(): void
    {
        $mysql = DB::connection( 'mysql' );

        $this->assertTrue(
            $mysql->getSchemaBuilder()->hasTable( 'zash_pay_terminal_attempts' ),
            'Staging MySQL must already have ZashPay migrations applied.'
        );

        $order = Order::on( 'mysql' )
            ->where( 'payment_status', Order::PAYMENT_UNPAID )
            ->whereDoesntHave( 'payments' )
            ->orderByDesc( 'id' )
            ->first();

        if ( ! $order ) {
            $this->markTestSkipped( 'No unpaid MySQL order available for concurrency probe.' );
        }

        config( ['database.default' => 'mysql'] );
        DB::setDefaultConnection( 'mysql' );
        DB::reconnect( 'mysql' );

        $optionKey = ZashPayAddonGate::OPTION_ENABLED;
        $existing = $mysql->table( 'nexopos_options' )->where( 'key', $optionKey )->whereNull( 'user_id' )->first();
        if ( $existing ) {
            $mysql->table( 'nexopos_options' )->where( 'id', $existing->id )->update( ['value' => 'yes'] );
        } else {
            $mysql->table( 'nexopos_options' )->insert( [
                'key' => $optionKey,
                'value' => 'yes',
                'user_id' => null,
                'array' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ] );
        }
        ns()->option->set( $optionKey, 'yes' );

        $author = User::on( 'mysql' )->orderBy( 'id' )->first();
        $this->assertNotNull( $author );

        /** @var TerminalPaymentService $service */
        $service = app( TerminalPaymentService::class );

        $result = $service->startCheckout( $order, [
            'idempotency_key' => 'mysql-concur-' . uniqid( '', true ),
            'provider' => 'simulator',
            'scenario' => 'approve',
            'terminal_id' => 'simulator-mysql-1',
        ], (int) $author->id );

        $this->assertTrue( (bool) ( $result['settled'] ?? false ) );
        $attempt = $result['attempt'];
        $this->assertInstanceOf( TerminalAttempt::class, $attempt );
        $this->assertNotNull( $attempt->order_payment_id );

        $paymentsBefore = OrderPayment::on( 'mysql' )->where( 'order_id', $order->id )->count();
        $this->assertGreaterThanOrEqual( 1, $paymentsBefore );

        $attemptId = (int) $attempt->id;

        DB::connection( 'mysql' )->transaction( function () use ( $attemptId, $order ): void {
            $orderLocked = Order::on( 'mysql' )->whereKey( $order->id )->lockForUpdate()->firstOrFail();
            TerminalAttempt::on( 'mysql' )->whereKey( $attemptId )->lockForUpdate()->firstOrFail();

            $linked = TerminalAttempt::on( 'mysql' )
                ->whereKey( $attemptId )
                ->where( 'order_id', $orderLocked->id )
                ->where( 'status', TerminalAttempt::STATUS_APPROVED )
                ->whereNull( 'order_payment_id' )
                ->update( ['order_payment_id' => 999999] );

            $this->assertSame( 0, $linked, 'Already-linked attempt must not accept a second payment link.' );
        } );

        $this->assertSame(
            $paymentsBefore,
            OrderPayment::on( 'mysql' )->where( 'order_id', $order->id )->count()
        );
        $fresh = TerminalAttempt::on( 'mysql' )->find( $attemptId );
        $this->assertNotNull( $fresh?->order_payment_id );
        $this->assertNotSame( 999999, (int) $fresh->order_payment_id );
    }
}

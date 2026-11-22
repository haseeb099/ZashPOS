<?php

namespace Modules\ZashPay\Models;

use App\Models\NsModel;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $order_id
 * @property int|null    $register_id
 * @property int         $author_id
 * @property string      $terminal_id
 * @property int         $amount_minor
 * @property string      $currency
 * @property string      $idempotency_key
 * @property string      $status
 * @property string      $provider
 * @property string|null $provider_ref
 * @property string|null $scenario
 * @property int|null    $order_payment_id
 * @property string|null $last_error
 */
class TerminalAttempt extends NsModel
{
    protected $table = 'zash_pay_terminal_attempts';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const STATUS_UNKNOWN = 'unknown';

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UNKNOWN,
        self::STATUS_TIMED_OUT,
    ];

    protected $fillable = [
        'order_id',
        'register_id',
        'author_id',
        'terminal_id',
        'amount_minor',
        'currency',
        'idempotency_key',
        'status',
        'provider',
        'provider_ref',
        'scenario',
        'order_payment_id',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'register_id' => 'integer',
            'author_id' => 'integer',
            'amount_minor' => 'integer',
            'order_payment_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class, 'order_id' );
    }

    public function orderPayment(): BelongsTo
    {
        return $this->belongsTo( OrderPayment::class, 'order_payment_id' );
    }

    public function isOpen(): bool
    {
        return in_array( $this->status, self::OPEN_STATUSES, true );
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->order_payment_id !== null;
    }
}

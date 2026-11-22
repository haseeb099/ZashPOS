<?php

namespace Modules\ZashPay\Models;

use App\Models\NsModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $terminal_attempt_id
 * @property int         $author_id
 * @property int         $amount_minor
 * @property string      $currency
 * @property string      $idempotency_key
 * @property string      $status
 * @property string      $provider
 * @property string|null $provider_ref
 * @property string|null $last_error
 */
class TerminalRefund extends NsModel
{
    protected $table = 'zash_pay_terminal_refunds';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'terminal_attempt_id',
        'author_id',
        'amount_minor',
        'currency',
        'idempotency_key',
        'status',
        'provider',
        'provider_ref',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'terminal_attempt_id' => 'integer',
            'author_id' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo( TerminalAttempt::class, 'terminal_attempt_id' );
    }
}

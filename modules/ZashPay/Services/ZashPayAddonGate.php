<?php

namespace Modules\ZashPay\Services;

use App\Models\PaymentType;
use App\Models\User;

class ZashPayAddonGate
{
    public const OPTION_ENABLED = 'zash_pay_integrated_enabled';

    public function isEnabled(): bool
    {
        $value = ns()->option->get( self::OPTION_ENABLED, 'no' );

        return $value === 'yes' || $value === true || $value === 1 || $value === '1';
    }

    public function assertEnabled(): void
    {
        if ( ! $this->isEnabled() ) {
            abort( 403, __( 'ZashPay integrated PDQ is disabled.' ) );
        }
    }

    public function syncPaymentTypeVisibility(): void
    {
        $authorId = (int) ( ns()->getValidAuthor() ?: User::query()->value( 'id' ) ?: 1 );

        $type = PaymentType::query()->where( 'identifier', 'card-integrated-pdq' )->first();

        if ( ! $type ) {
            $type = new PaymentType;
            $type->identifier = 'card-integrated-pdq';
            $type->author_id = $authorId;
        }

        $type->label = __( 'Card – integrated PDQ' );
        $type->active = $this->isEnabled();
        $type->readonly = true;
        $type->save();
    }
}

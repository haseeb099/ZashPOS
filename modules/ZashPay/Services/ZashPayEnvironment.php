<?php

namespace Modules\ZashPay\Services;

class ZashPayEnvironment
{
    /**
     * Simulator (scripted approve/decline) must not run on live installations.
     */
    public function allowsSimulator(): bool
    {
        return ! app()->environment( 'production' );
    }

    /**
     * Order-level accounting refunds through the till/journal are not ready.
     * Durable provider-refund recording stays gated behind this flag.
     */
    public function allowsAccountingRefunds(): bool
    {
        return false;
    }
}

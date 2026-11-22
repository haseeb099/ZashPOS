<?php

namespace Modules\ZashPay\Connectors;

use Modules\ZashPay\Models\TerminalAttempt;

interface TerminalConnector
{
    public function provider(): string;

    public function checkout( TerminalAttempt $attempt ): TerminalConnectorResult;

    public function status( TerminalAttempt $attempt ): TerminalConnectorResult;

    public function cancel( TerminalAttempt $attempt ): TerminalConnectorResult;

    public function refund( TerminalAttempt $attempt, int $amountMinor ): TerminalConnectorResult;
}

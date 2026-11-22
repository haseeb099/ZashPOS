<?php

namespace Modules\ZashPay\Connectors;

class TerminalConnectorResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerRef = null,
        public readonly ?string $message = null,
        public readonly array $meta = [],
    ) {}
}

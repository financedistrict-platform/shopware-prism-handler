<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Payment;

/**
 * Resolved Prism gateway coordinates for a single request/sales-channel.
 * Both fields are required and non-empty by construction (the resolver throws otherwise).
 *
 * @internal
 */
final readonly class PrismConfig
{
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
    ) {
    }
}

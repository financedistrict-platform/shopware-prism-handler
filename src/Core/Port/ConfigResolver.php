<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

use Fd\PrismPayment\Core\Payment\PrismConfig;

/**
 * Resolves the Prism gateway URL + API key per request/sales-channel. Implemented by
 * Infrastructure (system_config / env); Application depends only on this interface.
 *
 * @internal
 */
interface ConfigResolver
{
    public function resolve(?string $salesChannelId): PrismConfig;

    /**
     * The gateway base URL only — no API key, no sales channel required. Used by discovery
     * (describe) to build the public spec/schema URLs without depending on a configured key.
     */
    public function gatewayUrl(): string;
}

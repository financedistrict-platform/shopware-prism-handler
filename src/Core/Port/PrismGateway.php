<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Payment\SettleResult;

/**
 * The relay to the Prism merchant UCP API, as the domain needs it. Implemented by
 * Infrastructure (HTTP); Application depends only on this interface so it can be faked in tests.
 *
 * @internal
 */
interface PrismGateway
{
    /**
     * Per-session payment requirements. Returns the full handler entry {id, version, config}
     * verbatim (config = {x402Version, resource, accepts[], promotions?}).
     *
     * @return array<string, mixed>
     */
    public function paymentRequirements(
        PrismConfig $config,
        string $amount,
        string $currency,
        string $resourceUrl,
        ?string $resourceDescription,
    ): array;

    /**
     * Settle an x402 authorization on-chain. The whole `credential` (the wallet's signed x402
     * output) is relayed to Prism verbatim; Prism parses and validates its x402 internals.
     *
     * @param array<string, mixed> $credential
     */
    public function settle(PrismConfig $config, array $credential): SettleResult;
}

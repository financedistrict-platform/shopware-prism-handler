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
     * Settle an x402 authorization on-chain. `paymentPayload`/`paymentRequirements` are
     * relayed verbatim from the wallet's authorization output.
     *
     * @param array<string, mixed> $paymentPayload
     * @param array<string, mixed> $paymentRequirements
     */
    public function settle(PrismConfig $config, array $paymentPayload, array $paymentRequirements): SettleResult;
}

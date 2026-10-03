<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Payment\SettleResult;

interface PrismGateway
{
    /** @return array<string, mixed> */
    public function paymentRequirements(
        PrismConfig $config,
        string $ucpVersion,
        string $amount,
        string $currency,
        string $resourceUrl,
        ?string $resourceDescription,
    ): array;

    /** @param array<string, mixed> $credential */
    public function settle(PrismConfig $config, array $credential): SettleResult;
}

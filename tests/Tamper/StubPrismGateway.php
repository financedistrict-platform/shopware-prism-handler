<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Payment\SettleResult;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\PrismGateway;

final class StubPrismGateway implements PrismGateway, ConfigResolver
{
    public int $settlements = 0;

    public function paymentRequirements(PrismConfig $config, string $amount, string $currency, string $resourceUrl, ?string $resourceDescription): array
    {
        return [];
    }

    public function settle(PrismConfig $config, array $credential): SettleResult
    {
        ++$this->settlements;

        return new SettleResult(true, '0xsettled', 'base', '0xpayer', null);
    }

    public function resolve(?string $salesChannelId): PrismConfig
    {
        return new PrismConfig('https://prism.invalid', 'key');
    }

    public function gatewayUrl(): string
    {
        return 'https://prism.invalid';
    }
}

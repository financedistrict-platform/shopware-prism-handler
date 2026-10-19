<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

use Fd\PrismPayment\Core\Payment\PrismConfig;

interface SettlementSummarySource
{
    public function settlementSummary(PrismConfig $config, string $transactionHash): array;
}

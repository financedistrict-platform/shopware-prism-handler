<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Admin;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\SettlementReadModel;
use Fd\PrismPayment\Core\Port\SettlementSummarySource;
use Fd\PrismPayment\Core\Settlement\SettlementSummary;

final readonly class SettlementSummaryReader
{
    public function __construct(
        private SettlementReadModel $readModel,
        private ConfigResolver $configResolver,
        private SettlementSummarySource $source,
    ) {
    }

    public function forOrder(string $orderId): ?SettlementSummary
    {
        $view = $this->readModel->findByOrderId($orderId);
        if (null === $view) {
            return null;
        }

        if (null === $view->transactionHash || '' === $view->transactionHash
            || null === $view->salesChannelId || '' === $view->salesChannelId
        ) {
            return SettlementSummary::unavailable();
        }

        try {
            return SettlementSummary::fromPrism(
                $this->source->settlementSummary($this->configResolver->resolve($view->salesChannelId), $view->transactionHash),
            );
        } catch (PrismApiException) {
            return SettlementSummary::unavailable();
        }
    }
}

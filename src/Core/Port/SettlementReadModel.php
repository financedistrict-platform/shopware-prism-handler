<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

use Fd\PrismPayment\Core\Settlement\SettlementView;

/**
 * Read side of the settlement store, kept separate from {@see CredentialStore} (write lifecycle).
 *
 * @internal
 */
interface SettlementReadModel
{
    /**
     * The settled payment linked to the given order, or null when the order has no settled Prism
     * payment (not a Prism order, paid by another method, or not yet settled).
     */
    public function findByOrderId(string $orderId): ?SettlementView;
}

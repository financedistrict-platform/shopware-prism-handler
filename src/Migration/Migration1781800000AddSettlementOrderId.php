<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Add `order_id` to the settlement table so the admin card can look a row up by order (the row is
 * keyed by checkout session). Nullable — set at complete, once the order exists.
 */
final class Migration1781800000AddSettlementOrderId extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781800000;
    }

    public function update(Connection $connection): void
    {
        $column = $connection->fetchOne(
            "SHOW COLUMNS FROM `fd_prism_payment_settlement` LIKE 'order_id'",
        );

        if (false === $column) {
            $connection->executeStatement(
                'ALTER TABLE `fd_prism_payment_settlement`
                    ADD COLUMN `order_id` BINARY(16) NULL AFTER `checkout_session_id`,
                    ADD INDEX `idx.fd_prism_settlement.order_id` (`order_id`)',
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

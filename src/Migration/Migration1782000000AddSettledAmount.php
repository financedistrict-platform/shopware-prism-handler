<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1782000000AddSettledAmount extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1782000000;
    }

    public function update(Connection $connection): void
    {
        $column = $connection->fetchOne(
            "SHOW COLUMNS FROM `fd_prism_payment_settlement` LIKE 'settled_amount'",
        );

        if (false === $column) {
            $connection->executeStatement(
                'ALTER TABLE `fd_prism_payment_settlement`
                    ADD COLUMN `settled_amount` VARCHAR(32) NULL AFTER `network`,
                    ADD COLUMN `settled_currency` VARCHAR(3) NULL AFTER `settled_amount`',
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

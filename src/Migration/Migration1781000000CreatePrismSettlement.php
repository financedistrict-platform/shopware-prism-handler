<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1781000000CreatePrismSettlement extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `fd_prism_payment_settlement` (
                `checkout_session_id`  VARCHAR(64)  NOT NULL,
                `payment_payload`      LONGTEXT     NOT NULL,
                `payment_requirements` LONGTEXT     NOT NULL,
                `status`               VARCHAR(16)  NOT NULL,
                `transaction_hash`     VARCHAR(128) NULL,
                `network`              VARCHAR(64)  NULL,
                `created_at`           DATETIME(3)  NOT NULL,
                `updated_at`           DATETIME(3)  NULL,
                PRIMARY KEY (`checkout_session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

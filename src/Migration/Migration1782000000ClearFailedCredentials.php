<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Since 0.7.12 a settlement that fails drops its credential. Rows that failed before that still hold
 * one; this clears them so an upgraded install matches a fresh one. Nothing reads a failed row's
 * credential, and a new payment replaces it on capture.
 */
final class Migration1782000000ClearFailedCredentials extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1782000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            "UPDATE `fd_prism_payment_settlement` SET `credential` = NULL WHERE `status` = 'failed'",
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * F2 + F0/F1 schema support:
 *  - add `offered_accepts` (the accepts[] we offered, for the payment-binding check);
 *  - relax `payment_payload` / `payment_requirements` to NULL and default `status` to 'pending'
 *    so a row can be created **offer-first** (recordOffer at augment, before any credential);
 *  - the `status` vocabulary gains 'settling' (no enum constraint; VARCHAR(16) already fits).
 *
 * On a fresh install this runs right after Migration1781000000CreatePrismSettlement; on an
 * upgrade it alters the existing table in place. Non-destructive.
 */
final class Migration1781600000AddOfferedAccepts extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781600000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `fd_prism_payment_settlement`
                ADD COLUMN `offered_accepts` LONGTEXT NULL AFTER `payment_requirements`,
                MODIFY `payment_payload`      LONGTEXT     NULL,
                MODIFY `payment_requirements` LONGTEXT     NULL,
                MODIFY `status`               VARCHAR(16)  NOT NULL DEFAULT 'pending';
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

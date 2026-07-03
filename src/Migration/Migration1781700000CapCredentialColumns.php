<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * F4 — bound the agent-controlled credential columns. `payment_payload` / `payment_requirements`
 * are written verbatim from the agent's submitted x402 credential; as LONGTEXT (up to 4 GB) they
 * were an unbounded write surface (storage-abuse / cheap DoS). A real credential is ~1 KB, so cap
 * the columns at VARCHAR(4096).
 *
 * This is the structural backstop; the application also rejects oversized credentials at the
 * boundary (PrismCheckoutAdapter::extractCredential) with a clean 422 — that app check is the
 * primary gate, so a non-strict sql_mode can never silently truncate a credential into a corrupt
 * stored value. Columns stay NULLable for offer-first row creation. `offered_accepts` is left
 * LONGTEXT (it is Prism-issued, not agent-controlled).
 *
 * On an upgrade this alters the table in place; existing ~1 KB rows convert without loss.
 */
final class Migration1781700000CapCredentialColumns extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781700000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `fd_prism_payment_settlement`
                MODIFY `payment_payload`      VARCHAR(4096) NULL,
                MODIFY `payment_requirements` VARCHAR(4096) NULL;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Store the agent's x402 credential as ONE opaque object instead of two hand-split columns.
 *
 * The plugin's job is to carry the credential verbatim (agent -> store -> Prism), never to
 * disassemble it into `paymentPayload`/`paymentRequirements` and reassemble it on the way out —
 * that split is x402 knowledge that belongs in the wallet and Prism, not in a store plugin, and
 * hand-reassembly is the classic source of shape bugs. This migration adds a single `credential`
 * column that holds the wallet's whole signed output; the plugin reads into it only for the
 * read-only anti-scam match.
 *
 * Additive + safe: `update()` adds the column; `updateDestructive()` drops the now-unused split
 * columns (until it runs they simply sit NULL — nothing writes them anymore).
 *
 * The column is TEXT (stored off-row) rather than a large VARCHAR: while the split columns still
 * exist, three in-row VARCHARs under utf8mb4 would blow MySQL's 65535-byte row limit at DDL time.
 * The agent-controlled size bound is enforced primarily at the boundary (a clean 422 in
 * PrismCheckoutAdapter, MAX_CREDENTIAL_BYTES) — the same primary gate the split-column cap
 * (Migration1781700000) relied on.
 */
final class Migration1781900000AddCredentialColumn extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781900000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->settlementHasColumn($connection, 'credential')) {
            $connection->executeStatement(<<<'SQL'
                ALTER TABLE `fd_prism_payment_settlement`
                    ADD COLUMN `credential` TEXT NULL AFTER `offered_accepts`;
                SQL);
        }

        // Backfill existing rows from the split columns BEFORE updateDestructive drops them, so a
        // settlement recorded before this migration keeps the data the admin card renders (asset +
        // amount from paymentRequirements, payer from paymentPayload). Idempotent: fills only NULL
        // credentials, and only while the source columns still exist.
        if ($this->settlementHasColumn($connection, 'payment_payload')
            && $this->settlementHasColumn($connection, 'payment_requirements')
        ) {
            // Concatenate the two stored JSON strings into one object literal. MariaDB's JSON_OBJECT
            // would treat each column as a scalar string and double-encode it; CONCAT nests them
            // as-is (the columns already hold valid JSON written by json_encode).
            $connection->executeStatement(<<<'SQL'
                UPDATE `fd_prism_payment_settlement`
                SET `credential` = CONCAT(
                        '{"paymentPayload":', `payment_payload`,
                        ',"paymentRequirements":', `payment_requirements`, '}'
                    )
                WHERE `credential` IS NULL
                  AND `payment_payload` IS NOT NULL
                  AND `payment_requirements` IS NOT NULL;
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        foreach (['payment_payload', 'payment_requirements'] as $column) {
            if ($this->settlementHasColumn($connection, $column)) {
                $connection->executeStatement(sprintf(
                    'ALTER TABLE `fd_prism_payment_settlement` DROP COLUMN `%s`;',
                    $column,
                ));
            }
        }
    }

    private function settlementHasColumn(Connection $connection, string $column): bool
    {
        return (bool) $connection->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            ['table' => 'fd_prism_payment_settlement', 'column' => $column],
        );
    }
}

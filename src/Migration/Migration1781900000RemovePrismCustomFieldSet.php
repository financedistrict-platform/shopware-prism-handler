<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Migration;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Infrastructure\OrderCustomFields;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Retire the legacy "Prism payment" custom-field set (replaced by the dedicated card). Deleting the
 * set cascades to its relation + fields; residual order.custom_fields JSON keys are left inert.
 */
final class Migration1781900000RemovePrismCustomFieldSet extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781900000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'DELETE FROM `custom_field_set` WHERE `id` = UNHEX(:id)',
            ['id' => OrderCustomFields::SET_ID],
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}

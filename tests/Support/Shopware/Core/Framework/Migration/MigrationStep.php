<?php

declare(strict_types=1);

namespace Shopware\Core\Framework\Migration;

use Doctrine\DBAL\Connection;

abstract class MigrationStep
{
    abstract public function getCreationTimestamp(): int;

    abstract public function update(Connection $connection): void;

    abstract public function updateDestructive(Connection $connection): void;
}

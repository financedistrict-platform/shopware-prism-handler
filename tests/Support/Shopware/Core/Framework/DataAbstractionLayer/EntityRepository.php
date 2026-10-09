<?php

declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer;

use Shopware\Core\Framework\Context;

class EntityRepository
{
    /**
     * @param list<array<string, mixed>> $data
     */
    public function update(array $data, Context $context): mixed
    {
        return null;
    }
}

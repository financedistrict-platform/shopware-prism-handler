<?php

declare(strict_types=1);

namespace Shopware\Core\Framework;

class Context
{
    public static function createDefaultContext(): self
    {
        return new self();
    }
}

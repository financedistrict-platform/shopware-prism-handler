<?php

declare(strict_types=1);

namespace Shopware\Core\Framework;

final class Context
{
    public static function createDefaultContext(): self
    {
        return new self();
    }
}

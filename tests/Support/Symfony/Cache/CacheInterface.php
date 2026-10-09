<?php

declare(strict_types=1);

namespace Symfony\Contracts\Cache;

interface CacheInterface
{
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed;
}

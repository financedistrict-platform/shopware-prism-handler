<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class UncachedCache implements CacheInterface
{
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $callback(new class implements ItemInterface {
            public function expiresAfter(int|\DateInterval|null $time): static
            {
                return $this;
            }
        });
    }
}

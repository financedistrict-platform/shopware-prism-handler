<?php

declare(strict_types=1);

namespace Symfony\Contracts\Cache;

interface ItemInterface
{
    public function expiresAfter(int|\DateInterval|null $time): static;
}

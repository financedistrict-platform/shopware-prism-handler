<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Config;

class RuntimeConfiguration
{
    public function __construct(
        public readonly ?string $version = null,
        public readonly ?string $baseUri = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model;

use Ucp\Sdk\Model\Config\RuntimeConfiguration;

class RequestContext
{
    public function __construct(
        public readonly ?RuntimeConfiguration $runtimeConfiguration = null,
        public readonly string $host = "localhost",
    ) {
    }
}

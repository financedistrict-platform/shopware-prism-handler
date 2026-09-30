<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

final readonly class HandlerDeclaration
{
    public function __construct(
        public string $id,
        public string $version,
        public string $spec,
        public string $schema,
        public string $instrumentSchema,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

final class PaymentHandlerDescriptor
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $version = null,
        public readonly ?string $spec = null,
        public readonly ?string $configSchema = null,
        public readonly array $instrumentSchemas = [],
        public readonly array $availableInstruments = [],
    ) {
    }
}

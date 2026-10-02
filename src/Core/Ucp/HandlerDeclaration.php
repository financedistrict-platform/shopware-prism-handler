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

    public static function forGateway(string $gateway, string $declaredVersion, string $servedVersion): self
    {
        $base = rtrim($gateway, '/') . '/ucp/' . $servedVersion;

        return new self(
            id: HandlerId::PRISM,
            version: $declaredVersion,
            spec: $base . '/prism.md',
            schema: $base . '/schema.json',
            instrumentSchema: $base . '/instrument_schema.json',
        );
    }
}

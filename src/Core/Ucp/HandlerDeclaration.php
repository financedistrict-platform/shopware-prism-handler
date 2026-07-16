<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

/**
 * The Prism-owned payment-handler declaration: the id/version Prism advertises plus the public
 * contract URLs (spec, config schema, instrument schemas) an agent needs to pay. This is the data
 * the plugin re-advertises in UCP discovery — sourced live from Prism (the single source of truth)
 * so Prism can evolve it without a plugin redeploy.
 *
 * Pointer-only: it carries no x402/UCP payload shape, just the URLs where those shapes are defined.
 *
 * @internal
 */
final readonly class HandlerDeclaration
{
    /**
     * @param list<string> $instrumentSchemas
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $spec,
        public string $configSchema,
        public array $instrumentSchemas,
    ) {
    }
}

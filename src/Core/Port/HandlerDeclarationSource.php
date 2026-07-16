<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;

/**
 * Fetches the Prism-owned handler declaration from Prism's public UCP handlers endpoint.
 *
 * The endpoint is merchant-independent and public, so the fetch is keyless — discovery never needs
 * a merchant API key. Implementations throw on any failure (transport, non-2xx, malformed body,
 * missing entry/field) so the caller can fall back to a static default rather than break discovery.
 *
 * @internal
 */
interface HandlerDeclarationSource
{
    public function fetch(string $gatewayUrl): HandlerDeclaration;
}

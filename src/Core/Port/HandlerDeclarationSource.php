<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;

/**
 * Fetches the Prism-owned handler declaration from Prism's UCP handlers endpoint.
 *
 * Implementations throw on any failure (transport, non-2xx, malformed body,
 * missing entry/field) so the caller can fall back to a static default rather than break discovery.
 *
 * @internal
 */
interface HandlerDeclarationSource
{
    public function fetch(PrismConfig $config, ?string $ucpVersion): HandlerDeclaration;
}

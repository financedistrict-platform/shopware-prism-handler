<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Exception;

/**
 * Raised when a Prism call fails to transport, returns a non-2xx status, or returns a
 * payload missing fields we depend on. We fail fast rather than mask the gap — the
 * checkout/discovery surfaces a clear error instead of a fabricated success.
 *
 * @internal
 */
final class PrismApiException extends \RuntimeException
{
}

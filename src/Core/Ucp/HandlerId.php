<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

/**
 * The externally-stable UCP handler id (Prism's), independent of the (renameable) plugin
 * name. Lives in Core so every layer — including the pure response parser — can reference it
 * without depending on the Application-layer payment handler.
 *
 * @internal
 */
final class HandlerId
{
    public const PRISM = 'xyz.fd.prism_payment';
}

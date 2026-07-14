<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure;

/**
 * Id of the legacy "Prism payment" custom-field set.
 *
 * The set (and its single block-explorer-URL field) was the old merchant surface, auto-rendered by
 * Shopware's generic Custom-fields card. It has been retired in favour of the dedicated Prism card,
 * which reads the settlement table directly. This id survives only so the retirement migration and
 * a hard uninstall can delete the set from installs that still carry it.
 *
 * @internal
 */
final class OrderCustomFields
{
    public const SET_ID = 'fd70000000000000000000000000a001';
}

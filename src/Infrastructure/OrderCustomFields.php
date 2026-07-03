<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure;

/**
 * Identifiers for the "Prism payment" custom-field set shown on the order detail. The set is
 * attached to the `order` entity (which Shopware auto-renders a Custom-fields card for) so the
 * settlement is visible to operators without any admin UI code.
 *
 * Only the human-useful block-explorer link is shown here; the raw tx hash + network are kept
 * on the order transaction's custom fields and in the UCP complete response for machine use.
 *
 * Ids are fixed so install/activate upserts stay idempotent.
 *
 * @internal
 */
final class OrderCustomFields
{
    public const SET_NAME = 'fd_prism_payment';
    public const SET_ID = 'fd70000000000000000000000000a001';
    public const RELATION_ID = 'fd70000000000000000000000000a002';

    public const EXPLORER_URL = 'fd_prism_explorer_url';
    public const EXPLORER_ID = 'fd70000000000000000000000000a005';
}

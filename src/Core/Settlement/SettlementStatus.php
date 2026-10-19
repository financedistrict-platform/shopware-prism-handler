<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Settlement;

/**
 * The settlement lifecycle vocabulary, in one place so the state machine, the record, and the
 * persistence layer all agree.
 *
 *  - pending  — a credential is captured (or an offer recorded) and may be settled.
 *  - settling — the settle has been atomically claimed; the on-chain call is in flight.
 *  - settled  — terminal; the on-chain settle succeeded and the order was paid.
 *  - failed   — the settle failed; a fresh authorization may move it back to pending.
 *
 * Allowed transitions are owned by {@see SettlementStateMachine}; this class is only the names.
 *
 * @internal
 */
final class SettlementStatus
{
    public const PENDING = 'pending';

    public const SETTLING = 'settling';

    public const SETTLED = 'settled';

    public const FAILED = 'failed';
}

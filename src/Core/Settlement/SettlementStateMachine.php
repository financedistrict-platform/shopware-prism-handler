<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Settlement;

/**
 * The forward-only settlement state machine (F0/F1). Pure: no I/O, no framework — it only
 * answers "is this move legal?". The Infrastructure layer enforces these same rules
 * atomically in SQL (conditional upsert + claim); this class is the canonical specification
 * and the exhaustively unit-tested source of truth.
 *
 * Allowed transitions:
 *   pending  -> settling   (claim the settle)
 *   pending  -> failed     (settle rejected before it was claimed)
 *   settling -> settled    (on-chain settle confirmed)
 *   settling -> failed     (on-chain settle rejected)
 *   failed   -> pending    (retry with a fresh authorization, via capture)
 *   settled  -> <nothing>  (TERMINAL — never reverts; this is the F0 guarantee)
 *
 * @internal
 */
final class SettlementStateMachine
{
    /** @var array<string, list<string>> from-status => allowed to-statuses */
    private const ALLOWED = [
        SettlementStatus::PENDING => [SettlementStatus::SETTLING, SettlementStatus::FAILED],
        SettlementStatus::SETTLING => [SettlementStatus::SETTLED, SettlementStatus::FAILED],
        SettlementStatus::FAILED => [SettlementStatus::PENDING],
        SettlementStatus::SETTLED => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return \in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function isTerminal(string $status): bool
    {
        return [] === (self::ALLOWED[$status] ?? []);
    }

    /**
     * Whether a credential capture may (re)write a row in this status. Only `pending` and `failed`
     * rows may be (re)captured — the agent can submit or replace an authorization. A `settled` row
     * is terminal (the F0 fix), and a `settling` row is mid-settle: recapturing it would reset it to
     * `pending` and let a second `complete` re-claim and settle again (a double-settle race), so it
     * is refused too.
     */
    public function mayCapture(string $status): bool
    {
        return SettlementStatus::PENDING === $status || SettlementStatus::FAILED === $status;
    }

    /**
     * Whether a settle may be atomically claimed (pending -> settling) from this status.
     * Only a pending row may be claimed; this is the single gate that makes settle once-only
     * under concurrent completes and crash-retries (the F1 fix).
     */
    public function mayClaimSettle(string $status): bool
    {
        return SettlementStatus::PENDING === $status;
    }
}

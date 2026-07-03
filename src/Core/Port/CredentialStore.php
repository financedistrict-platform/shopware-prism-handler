<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;

/**
 * Durable per-session store for the x402 credential + settlement status. Implemented by
 * Infrastructure (DBAL); Application depends only on this interface.
 *
 * @internal
 */
interface CredentialStore
{
    /**
     * Record a (re)quoted offer for a session, creating the row offer-first if needed. Writes only
     * the offer (full handler entry + the fiat amount/currency it was quoted for) — column-disjoint
     * from the credential/status columns. Never overwrites a settled row (F0).
     *
     * @param array<string, mixed> $offeredEntry the full handler entry {id,version,config}
     */
    public function recordOffer(string $sessionId, string $quotedAmount, string $quotedCurrency, array $offeredEntry): void;

    /**
     * Invalidate the captured Prism credential because the cart amount changed after it was signed
     * (or a settle failed): clear the credential and move the row to `failed` so complete REFUSES
     * (the agent must re-sign for the new amount). No-op on a settled row (F0).
     */
    public function invalidateCredential(string $sessionId): void;

    /**
     * Release our claim on a session because the agent selected a DIFFERENT payment method: clear
     * any captured credential and return the row to `pending` so complete defers to the base flow.
     * We only answer to the Prism handler. No-op on a settled row (F0).
     */
    public function releaseToBase(string $sessionId): void;

    /**
     * Capture (or replace) the pending credential for a session. Must NEVER overwrite a settled
     * row (F0): the implementation guards the upsert so a settled row is a no-op.
     *
     * @param array<string, mixed> $paymentPayload
     * @param array<string, mixed> $paymentRequirements
     */
    public function capture(string $sessionId, array $paymentPayload, array $paymentRequirements): void;

    /**
     * Atomically claim the settle: move pending -> settling and report whether THIS caller won.
     * Returns true only if exactly one row transitioned (the F1 gate); false means the row was
     * not pending (already settling/settled/failed, or absent), and the caller must not settle.
     */
    public function claim(string $sessionId): bool;

    public function load(string $sessionId): ?PrismSettlementRecord;

    public function markSettled(string $sessionId, string $transactionHash, string $network): void;

    public function markFailed(string $sessionId): void;
}

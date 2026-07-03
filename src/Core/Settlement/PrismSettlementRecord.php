<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Settlement;

/**
 * Per-checkout-session settlement state, persisted across the UCP lifecycle and used to keep
 * settlement once-only (F0/F1) and bound to what we offered (F2).
 *
 * The offer is **cart-driven**: the augmenter records the full handler entry it last offered
 * together with the fiat amount/currency it was quoted for. While the cart amount is unchanged
 * the same offer is served verbatim (so what the agent signs == what we store == what we verify);
 * a change of amount re-quotes and invalidates any captured credential (the old signature no
 * longer matches the new amount).
 *
 * The row may exist **offer-first**: `recordOffer` writes the offer before any credential is
 * captured, so `paymentPayload`/`paymentRequirements` are nullable — null means "no (valid)
 * credential captured yet".
 *
 * @internal
 */
final readonly class PrismSettlementRecord
{
    /**
     * @param array<string, mixed>|null $paymentPayload
     * @param array<string, mixed>|null $paymentRequirements
     * @param array<string, mixed>|null $offeredEntry  the full handler entry {id,version,config} we last offered
     */
    public function __construct(
        public string $checkoutSessionId,
        public ?array $paymentPayload,
        public ?array $paymentRequirements,
        public string $status,
        public ?string $transactionHash,
        public ?string $network,
        public ?array $offeredEntry = null,
        public ?string $quotedAmount = null,
        public ?string $quotedCurrency = null,
    ) {
    }

    /**
     * True once the agent has submitted an x402 credential for this session that is still valid
     * for the current offer. A row that only holds an offer (no credential), or whose credential
     * was invalidated by a cart change, is not settleable — complete defers to the base flow.
     */
    public function hasCredential(): bool
    {
        return null !== $this->paymentPayload && null !== $this->paymentRequirements;
    }

    /**
     * The accepts[] from the offered entry, for the F2 binding check. Null when no offer is
     * recorded (fail-closed at complete).
     *
     * @return list<mixed>|null
     */
    public function offeredAccepts(): ?array
    {
        $accepts = $this->offeredEntry['config']['accepts'] ?? null;

        return \is_array($accepts) ? array_values($accepts) : null;
    }

    /**
     * Whether the recorded offer was quoted for exactly this cart (same fiat amount + currency),
     * so it can be reused verbatim instead of re-quoting Prism.
     */
    public function offerMatchesQuote(string $amount, string $currency): bool
    {
        return null !== $this->offeredEntry
            && $this->quotedAmount === $amount
            && $this->quotedCurrency === $currency;
    }

    public function isSettled(): bool
    {
        return SettlementStatus::SETTLED === $this->status;
    }

    public function isFailed(): bool
    {
        return SettlementStatus::FAILED === $this->status;
    }
}

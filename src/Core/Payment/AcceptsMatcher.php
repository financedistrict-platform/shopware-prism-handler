<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Payment;

/**
 * The F2 payment-binding control: assert that the payment requirements the agent submitted is
 * one we actually offered for this session. A **binding check, not an accounting check** — we
 * never read the amount, do FX, or compare to the cart total. We only assert membership by
 * full-object deep equality (key order normalized), so a valid payment whose chosen accept was
 * altered (e.g. a lowered amount) is not a member and is rejected.
 *
 * Pure: operates on already-decoded arrays. No I/O, no framework.
 *
 * @internal
 */
final class AcceptsMatcher
{
    /**
     * @param list<mixed>          $offeredAccepts        the accepts[] verbatim as Prism offered them
     * @param array<string, mixed> $submittedRequirements the single accept the agent chose
     */
    public function matches(array $offeredAccepts, array $submittedRequirements): bool
    {
        if ([] === $offeredAccepts) {
            return false; // fail-closed: nothing was offered to match against
        }

        $needle = $this->canonical($submittedRequirements);

        foreach ($offeredAccepts as $offer) {
            if (\is_array($offer) && $this->canonical($offer) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonical JSON for deep equality: object (associative) keys are sorted recursively so
     * key order is irrelevant; list order is preserved (it is significant).
     *
     * @param array<array-key, mixed> $value
     */
    private function canonical(array $value): string
    {
        return json_encode($this->normalize($value), \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function normalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}

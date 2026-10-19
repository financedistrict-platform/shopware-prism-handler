<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Payment;

/**
 * Parsed Prism `POST /api/v2/payment/settle` response (SettleResponse).
 * `transaction` is the on-chain tx hash; `network` is the CAIP-2 chain id.
 *
 * Prism binds each settle to its checkout, so its answer is final unless it says otherwise. The two
 * "still running" reasons mean wait. `checkout_already_settled` means another authorization paid the
 * checkout; it carries that transaction, and without one it cannot be recorded yet, so it waits too.
 *
 * @internal
 */
final readonly class SettleResult
{
    private const PENDING_REASONS = [
        'settlement_pending',
        'checkout_settlement_in_progress',
    ];

    private const ALREADY_SETTLED = 'checkout_already_settled';

    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public ?string $payer,
        public ?string $errorReason,
    ) {
    }

    public function isPending(): bool
    {
        return !$this->success
            && (\in_array($this->errorReason, self::PENDING_REASONS, true)
                || (self::ALREADY_SETTLED === $this->errorReason && '' === $this->transaction));
    }

    public function isAlreadySettled(): bool
    {
        return !$this->success && self::ALREADY_SETTLED === $this->errorReason && '' !== $this->transaction;
    }
}

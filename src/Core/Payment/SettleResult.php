<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Payment;

/**
 * Parsed Prism `POST /api/v2/payment/settle` response (SettleResponse).
 * `transaction` is the on-chain tx hash; `network` is the CAIP-2 chain id.
 *
 * @internal
 */
final readonly class SettleResult
{
    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public ?string $payer,
        public ?string $errorReason,
    ) {
    }
}

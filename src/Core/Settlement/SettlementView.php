<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Settlement;

/**
 * Order-keyed read projection of a settled payment for the admin card: raw on-chain facts, not
 * humanized (the admin owns the token/network map).
 *
 * @internal
 */
final readonly class SettlementView
{
    public function __construct(
        public string $network,
        public ?string $asset,
        public ?string $amountAtomic,
        public ?string $transactionHash,
        public ?string $settledAt,
        public ?string $fiatAmount,
        public ?string $fiatCurrency,
        public ?string $payer,
    ) {
    }
}

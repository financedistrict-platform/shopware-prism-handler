<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Settlement;

final readonly class SettlementSummary
{
    private function __construct(
        public string $state,
        public ?string $net = null,
        public ?string $cashback = null,
        public ?string $fee = null,
        public ?string $asset = null,
    ) {
    }

    public static function unavailable(): self
    {
        return new self('unavailable');
    }

    public static function fromPrism(array $body): self
    {
        $status = $body['status'] ?? null;

        if ('broadcasted' === $status) {
            return new self('pending');
        }

        if ('confirmed' === $status
            && self::isFilled($body['net'] ?? null)
            && self::isFilled($body['cashback'] ?? null)
            && self::isFilled($body['fee'] ?? null)
            && self::isFilled($body['asset'] ?? null)
        ) {
            return new self('confirmed', $body['net'], $body['cashback'], $body['fee'], $body['asset']);
        }

        return self::unavailable();
    }

    public function toArray(): array
    {
        if ('confirmed' !== $this->state) {
            return ['state' => $this->state];
        }

        return [
            'state' => $this->state,
            'net' => $this->net,
            'cashback' => $this->cashback,
            'fee' => $this->fee,
            'asset' => $this->asset,
        ];
    }

    private static function isFilled(mixed $value): bool
    {
        return \is_string($value) && '' !== $value;
    }
}

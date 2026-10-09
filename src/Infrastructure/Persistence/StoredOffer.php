<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Persistence;

final readonly class StoredOffer
{
    public function __construct(
        public array $entry,
        public ?string $quotedAmount,
        public ?string $quotedCurrency,
        public ?\DateTimeImmutable $quotedAt,
    ) {
    }

    public static function fromArray(array $offer): ?self
    {
        $entry = $offer['entry'] ?? null;
        if (!\is_array($entry)) {
            return null;
        }

        return new self(
            $entry,
            isset($offer['quotedAmount']) ? (string) $offer['quotedAmount'] : null,
            isset($offer['quotedCurrency']) ? (string) $offer['quotedCurrency'] : null,
            self::quotedAt($offer['quotedAt'] ?? null),
        );
    }

    public function toArray(): array
    {
        return [
            'quotedAmount' => $this->quotedAmount,
            'quotedCurrency' => $this->quotedCurrency,
            'quotedAt' => $this->quotedAt?->format(\DATE_ATOM),
            'entry' => $this->entry,
        ];
    }

    private static function quotedAt(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $quotedAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);

        return false === $quotedAt ? null : $quotedAt;
    }
}

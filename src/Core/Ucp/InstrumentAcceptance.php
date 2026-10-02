<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

final class InstrumentAcceptance
{
    public const INSTRUMENT_TYPE = 'x402';

    private const ACCEPTED_INSTRUMENT_TYPES = [self::INSTRUMENT_TYPE, 'tokenized', 'default', ''];

    public static function isPrismHandler(?string $handlerId): bool
    {
        return null !== $handlerId && \in_array($handlerId, HandlerId::ALL, true);
    }

    public static function acceptsInstrumentType(?string $type): bool
    {
        return \in_array($type ?? '', self::ACCEPTED_INSTRUMENT_TYPES, true);
    }

    public static function acceptsCredentialType(mixed $type): bool
    {
        return null === $type || self::INSTRUMENT_TYPE === $type;
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use Fd\PrismPayment\Core\Ucp\InstrumentAcceptance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HandlerIdAliasTest extends TestCase
{
    #[DataProvider('prismHandlerIds')]
    public function testAcceptsCanonicalAndLegacyHandlerId(string $handlerId): void
    {
        self::assertTrue(InstrumentAcceptance::isPrismHandler($handlerId));
    }

    public static function prismHandlerIds(): iterable
    {
        yield 'canonical' => ['xyz.fd.prism_payment'];
        yield 'legacy' => ['x402'];
    }

    #[DataProvider('otherHandlerIds')]
    public function testRejectsOtherHandlerIds(?string $handlerId): void
    {
        self::assertFalse(InstrumentAcceptance::isPrismHandler($handlerId));
    }

    public static function otherHandlerIds(): iterable
    {
        yield 'other handler' => ['com.example.card'];
        yield 'empty' => [''];
        yield 'missing' => [null];
        yield 'case differs' => ['X402'];
    }

    #[DataProvider('acceptedInstrumentTypes')]
    public function testAcceptsOriginalEraInstrumentTypes(?string $type): void
    {
        self::assertTrue(InstrumentAcceptance::acceptsInstrumentType($type));
    }

    public static function acceptedInstrumentTypes(): iterable
    {
        yield 'x402' => ['x402'];
        yield 'tokenized' => ['tokenized'];
        yield 'default' => ['default'];
        yield 'empty' => [''];
        yield 'missing' => [null];
    }

    public function testRejectsUnrelatedInstrumentType(): void
    {
        self::assertFalse(InstrumentAcceptance::acceptsInstrumentType('card'));
    }

    #[DataProvider('acceptedCredentialTypes')]
    public function testAcceptsX402OrMissingCredentialType(?string $type): void
    {
        self::assertTrue(InstrumentAcceptance::acceptsCredentialType($type));
    }

    public static function acceptedCredentialTypes(): iterable
    {
        yield 'x402' => ['x402'];
        yield 'missing' => [null];
    }

    #[DataProvider('rejectedCredentialTypes')]
    public function testRejectsOtherCredentialTypes(mixed $type): void
    {
        self::assertFalse(InstrumentAcceptance::acceptsCredentialType($type));
    }

    public static function rejectedCredentialTypes(): iterable
    {
        yield 'card' => ['card'];
        yield 'empty' => [''];
        yield 'not a string' => [402];
    }
}

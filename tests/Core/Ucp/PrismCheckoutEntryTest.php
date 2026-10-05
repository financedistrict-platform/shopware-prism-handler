<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use Fd\PrismPayment\Core\Ucp\PrismCheckoutEntry;
use PHPUnit\Framework\TestCase;

final class PrismCheckoutEntryTest extends TestCase
{
    public function testEntryIdAndVersionEqualTheDeclarationForTheSameUcpVersion(): void
    {
        $declaration = new HandlerDeclaration(
            HandlerId::PRISM,
            '2026-10-07',
            'https://prism.example/ucp/2026-08-25/prism.md',
            'https://prism.example/ucp/2026-08-25/schema.json',
            'https://prism.example/ucp/2026-08-25/instrument_schema.json',
        );

        $entry = PrismCheckoutEntry::compose($declaration, ['x402Version' => 2, 'accepts' => []]);

        self::assertNotNull($entry);
        self::assertSame($declaration->id, $entry['id']);
        self::assertSame($declaration->version, $entry['version']);
    }

    public function testEntryMatchesTheFallbackDeclarationDiscoveryServes(): void
    {
        $declaration = HandlerDeclaration::forGateway('https://prism.example', '2026-10-07', '2026-04-08');

        $entry = PrismCheckoutEntry::compose($declaration, ['x402Version' => 2, 'accepts' => []]);

        self::assertNotNull($entry);
        self::assertSame($declaration->id, $entry['id']);
        self::assertSame($declaration->version, $entry['version']);
    }

    public function testEntryCarriesTheRawConfigVerbatim(): void
    {
        $config = ['x402Version' => 2, 'accepts' => [['scheme' => 'exact']], 'promotions' => [['id' => 'p1']]];
        $declaration = HandlerDeclaration::forGateway('https://prism.example', '2026-10-07', '2026-04-08');

        $entry = PrismCheckoutEntry::compose($declaration, $config);

        self::assertNotNull($entry);
        self::assertSame($config, $entry['config']);
    }

    public function testNoDeclarationMeansNoEntry(): void
    {
        self::assertNull(PrismCheckoutEntry::compose(null, ['x402Version' => 2, 'accepts' => []]));
    }
}

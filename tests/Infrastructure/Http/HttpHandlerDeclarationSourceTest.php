<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Infrastructure\Http;

use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Ucp\PrismUserAgent;
use Fd\PrismPayment\Infrastructure\Http\HttpHandlerDeclarationSource;
use Fd\PrismPayment\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;

final class HttpHandlerDeclarationSourceTest extends TestCase
{
    private const BASE_URL = 'https://prism.example';

    public function testHandlersRequestUsesVersionedPathAndConstantUserAgent(): void
    {
        $client = new RecordingHttpClient([self::handlers(withInstrumentSchema: true)]);

        (new HttpHandlerDeclarationSource($client))->fetch(new PrismConfig(self::BASE_URL, 'key'), '2026-04-08');

        self::assertCount(1, $client->requests);
        self::assertSame('https://prism.example/api/v2/merchant/ucp/2026-04-08/handlers', $client->requests[0]['url']);
        self::assertSame(PrismUserAgent::VALUE, $client->userAgent(0));
    }

    public function testSchemaRequestCarriesTheSameUserAgent(): void
    {
        $client = new RecordingHttpClient([
            self::handlers(withInstrumentSchema: false),
            ['$defs' => ['xyz.fd.prism_payment' => ['instrument' => ['$ref' => self::BASE_URL . '/ucp/instrument_schema.json']]]],
        ]);

        (new HttpHandlerDeclarationSource($client))->fetch(new PrismConfig(self::BASE_URL, 'key'), '2026-04-08');

        self::assertCount(2, $client->requests);
        self::assertSame(self::BASE_URL . '/ucp/schema.json', $client->requests[1]['url']);
        self::assertSame(PrismUserAgent::VALUE, $client->userAgent(0));
        self::assertSame(PrismUserAgent::VALUE, $client->userAgent(1));
    }

    /**
     * @return array<string, mixed>
     */
    private static function handlers(bool $withInstrumentSchema): array
    {
        $entry = [
            'id' => 'xyz.fd.prism_payment',
            'version' => '2026-10-07',
            'spec' => self::BASE_URL . '/ucp/prism.md',
            'schema' => self::BASE_URL . '/ucp/schema.json',
            'available_instruments' => [['type' => 'x402']],
            'config' => [],
        ];
        if ($withInstrumentSchema) {
            $entry['instrument_schemas'] = [self::BASE_URL . '/ucp/instrument_schema.json'];
        }

        return ['xyz.fd.prism_payment' => [$entry]];
    }
}

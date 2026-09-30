<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Ucp\HandlerDeclarationParser;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HandlerDeclarationParserTest extends TestCase
{
    private const GATEWAY = 'https://prism-gw.fd.xyz';

    private const HANDLERS_BODY = <<<'JSON'
        { "xyz.fd.prism_payment": [ {
            "id": "xyz.fd.prism_payment",
            "version": "2026-10-07",
            "spec": "https://prism-gw.fd.xyz/ucp/prism.md",
            "schema": "https://prism-gw.fd.xyz/ucp/schema.json",
            "available_instruments": [ { "type": "x402" } ],
            "config": {} } ] }
        JSON;

    private const SCHEMA_DOCUMENT = <<<'JSON'
        { "$defs": { "xyz.fd.prism_payment": {
            "business_schema": {},
            "platform_schema": {},
            "response_schema": { "$ref": "https://ucp.dev/2026-08-25/schemas/payment_handler.json" },
            "instrument": { "$ref": "https://prism-gw.fd.xyz/ucp/instrument_schema.json" } } } }
        JSON;

    public function testParsesContractBodyAndLinkedInstrumentSchema(): void
    {
        $declaration = HandlerDeclarationParser::parse(self::handlersBody(), self::schemaDocument(), self::GATEWAY);

        self::assertSame(HandlerId::PRISM, $declaration->id);
        self::assertSame('2026-10-07', $declaration->version);
        self::assertSame(self::GATEWAY . '/ucp/prism.md', $declaration->spec);
        self::assertSame(self::GATEWAY . '/ucp/schema.json', $declaration->schema);
        self::assertSame(self::GATEWAY . '/ucp/instrument_schema.json', $declaration->instrumentSchema);
    }

    public function testSchemaUrlReturnsValidatedEntrySchema(): void
    {
        self::assertSame(
            self::GATEWAY . '/ucp/schema.json',
            HandlerDeclarationParser::schemaUrl(self::handlersBody(), self::GATEWAY),
        );
    }

    public function testAcceptsGatewayBaseUrlWithTrailingSlash(): void
    {
        $declaration = HandlerDeclarationParser::parse(self::handlersBody(), self::schemaDocument(), self::GATEWAY . '/');

        self::assertSame(HandlerId::PRISM, $declaration->id);
    }

    #[DataProvider('requiredFields')]
    public function testThrowsWhenRequiredFieldMissing(string $field): void
    {
        $body = self::handlersBody();
        unset($body[HandlerId::PRISM][0][$field]);

        $this->expectException(PrismApiException::class);
        $this->expectExceptionMessage(sprintf('"%s"', $field));
        HandlerDeclarationParser::parse($body, self::schemaDocument(), self::GATEWAY);
    }

    #[DataProvider('requiredFields')]
    public function testThrowsWhenRequiredFieldEmpty(string $field): void
    {
        $body = self::handlersBody();
        $body[HandlerId::PRISM][0][$field] = '';

        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse($body, self::schemaDocument(), self::GATEWAY);
    }

    public static function requiredFields(): iterable
    {
        yield 'id' => ['id'];
        yield 'version' => ['version'];
        yield 'spec' => ['spec'];
        yield 'schema' => ['schema'];
    }

    public function testThrowsWhenHandlerEntryMissing(): void
    {
        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse(['some.other.handler' => [['id' => 'x']]], self::schemaDocument(), self::GATEWAY);
    }

    public function testThrowsWhenHandlerEntryListEmpty(): void
    {
        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse([HandlerId::PRISM => []], self::schemaDocument(), self::GATEWAY);
    }

    public function testThrowsWhenIdIsLegacyX402(): void
    {
        $body = self::handlersBody();
        $body[HandlerId::PRISM][0]['id'] = 'x402';

        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse($body, self::schemaDocument(), self::GATEWAY);
    }

    public function testThrowsWhenNoX402AvailableInstrument(): void
    {
        $body = self::handlersBody();
        $body[HandlerId::PRISM][0]['available_instruments'] = [['type' => 'card']];

        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse($body, self::schemaDocument(), self::GATEWAY);
    }

    public function testThrowsWhenAvailableInstrumentsMissing(): void
    {
        $body = self::handlersBody();
        unset($body[HandlerId::PRISM][0]['available_instruments']);

        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse($body, self::schemaDocument(), self::GATEWAY);
    }

    public function testThrowsWhenInstrumentRefMissing(): void
    {
        $schema = self::schemaDocument();
        unset($schema['$defs'][HandlerId::PRISM]['instrument']['$ref']);

        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse(self::handlersBody(), $schema, self::GATEWAY);
    }

    public function testThrowsWhenHandlerDefsMissing(): void
    {
        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse(self::handlersBody(), ['$defs' => []], self::GATEWAY);
    }

    #[DataProvider('foreignUrls')]
    public function testThrowsWhenSchemaUrlIsForeign(string $url): void
    {
        $body = self::handlersBody();
        $body[HandlerId::PRISM][0]['schema'] = $url;

        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::schemaUrl($body, self::GATEWAY);
    }

    #[DataProvider('foreignUrls')]
    public function testThrowsWhenSpecUrlIsForeign(string $url): void
    {
        $body = self::handlersBody();
        $body[HandlerId::PRISM][0]['spec'] = $url;

        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse($body, self::schemaDocument(), self::GATEWAY);
    }

    #[DataProvider('foreignUrls')]
    public function testThrowsWhenInstrumentRefIsForeign(string $url): void
    {
        $schema = self::schemaDocument();
        $schema['$defs'][HandlerId::PRISM]['instrument']['$ref'] = $url;

        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse(self::handlersBody(), $schema, self::GATEWAY);
    }

    public static function foreignUrls(): iterable
    {
        yield 'other host' => ['https://evil.example/ucp/schema.json'];
        yield 'plain http' => ['http://prism-gw.fd.xyz/ucp/schema.json'];
        yield 'host suffix' => ['https://prism-gw.fd.xyz.evil.example/ucp/schema.json'];
        yield 'other port' => ['https://prism-gw.fd.xyz:8443/ucp/schema.json'];
        yield 'userinfo' => ['https://user@prism-gw.fd.xyz/ucp/schema.json'];
        yield 'relative' => ['/ucp/schema.json'];
    }

    public function testThrowsWhenGatewayIsNotHttps(): void
    {
        $this->expectException(PrismApiException::class);
        HandlerDeclarationParser::parse(self::handlersBody(), self::schemaDocument(), 'http://prism-gw.fd.xyz');
    }

    private static function handlersBody(): array
    {
        return json_decode(self::HANDLERS_BODY, true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function schemaDocument(): array
    {
        return json_decode(self::SCHEMA_DOCUMENT, true, 512, \JSON_THROW_ON_ERROR);
    }
}

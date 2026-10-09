<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Payment;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismResponseParser;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use PHPUnit\Framework\TestCase;

final class PrismResponseParserTest extends TestCase
{
    private PrismResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PrismResponseParser();
    }

    public function testReturnsRawConfigForValidPaymentRequirements(): void
    {
        $body = [
            'x402Version' => 2,
            'resource' => ['url' => 'https://shop.example/c/1'],
            'accepts' => [['scheme' => 'exact']],
            'promotions' => [['id' => 'p1']],
        ];

        self::assertSame($body, $this->parser->paymentRequirementsConfig($body));
    }

    public function testThrowsWhenConfigLacksX402Version(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->paymentRequirementsConfig(['accepts' => []]);
    }

    public function testThrowsWhenConfigLacksAccepts(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->paymentRequirementsConfig(['x402Version' => 2]);
    }

    public function testThrowsWhenConfigAcceptsNotArray(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->paymentRequirementsConfig(['x402Version' => 2, 'accepts' => 'nope']);
    }

    public function testThrowsOnWrappedHandlerEntryShape(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->paymentRequirementsConfig([HandlerId::PRISM => [[
            'id' => 'x',
            'version' => 'v',
            'config' => ['x402Version' => 2, 'accepts' => []],
        ]]]);
    }

    public function testParsesSuccessfulSettleResult(): void
    {
        $result = $this->parser->settleResult([
            'success' => true,
            'transaction' => '0xabc',
            'network' => 'eip155:97',
            'payer' => '0xdef',
        ]);

        self::assertTrue($result->success);
        self::assertSame('0xabc', $result->transaction);
        self::assertSame('eip155:97', $result->network);
        self::assertSame('0xdef', $result->payer);
        self::assertNull($result->errorReason);
    }

    public function testParsesUnsuccessfulSettleResultWithReason(): void
    {
        $result = $this->parser->settleResult([
            'success' => false,
            'transaction' => '',
            'network' => '',
            'errorReason' => 'insufficient funds',
        ]);

        self::assertFalse($result->success);
        self::assertSame('insufficient funds', $result->errorReason);
        self::assertNull($result->payer);
    }

    public function testThrowsWhenSettleMissingRequiredField(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->settleResult(['success' => true, 'transaction' => '0xabc']);
    }
}

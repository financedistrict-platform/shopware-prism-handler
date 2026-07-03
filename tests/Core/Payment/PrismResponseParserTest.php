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

    public function testReturnsFirstHandlerEntryForValidPaymentRequirements(): void
    {
        $entry = [
            'id' => 'x402',
            'version' => '2026-01-15',
            'config' => ['x402Version' => 2, 'accepts' => [['scheme' => 'exact']]],
        ];

        $result = $this->parser->paymentRequirementsEntry([HandlerId::PRISM => [$entry]]);

        self::assertSame($entry, $result);
    }

    public function testThrowsWhenHandlerKeyMissing(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->paymentRequirementsEntry(['some.other.handler' => [['id' => 'x']]]);
    }

    public function testThrowsWhenHandlerEntryListEmpty(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->paymentRequirementsEntry([HandlerId::PRISM => []]);
    }

    public function testThrowsWhenRequiredEntryKeyMissing(): void
    {
        $this->expectException(PrismApiException::class);
        // missing 'config'
        $this->parser->paymentRequirementsEntry([HandlerId::PRISM => [['id' => 'x402', 'version' => 'v']]]);
    }

    public function testThrowsWhenConfigLacksX402Version(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->paymentRequirementsEntry([HandlerId::PRISM => [[
            'id' => 'x402',
            'version' => 'v',
            'config' => ['accepts' => []],
        ]]]);
    }

    public function testThrowsWhenConfigAcceptsNotArray(): void
    {
        $this->expectException(PrismApiException::class);
        $this->parser->paymentRequirementsEntry([HandlerId::PRISM => [[
            'id' => 'x402',
            'version' => 'v',
            'config' => ['x402Version' => 2, 'accepts' => 'nope'],
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
        // missing 'network'
        $this->parser->settleResult(['success' => true, 'transaction' => '0xabc']);
    }
}

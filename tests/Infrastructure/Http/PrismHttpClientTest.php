<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Infrastructure\Http;

use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Payment\PrismResponseParser;
use Fd\PrismPayment\Infrastructure\Http\PrismHttpClient;
use Fd\PrismPayment\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;

final class PrismHttpClientTest extends TestCase
{
    public function testPaymentRequirementsSendsUcpVersionUserAgent(): void
    {
        $entry = [
            'id' => 'xyz.fd.prism_payment',
            'version' => '2026-10-07',
            'config' => ['x402Version' => 2, 'accepts' => [['scheme' => 'exact']]],
        ];
        $http = new RecordingHttpClient([['xyz.fd.prism_payment' => [$entry]]]);

        (new PrismHttpClient($http, new PrismResponseParser()))
            ->paymentRequirements(new PrismConfig('https://prism.example', 'key'), '2026-08-25', '10.00', 'USD', 'https://shop.example/c/1', null);

        self::assertSame('https://prism.example/api/v2/merchant/ucp/payment-requirements', $http->requests[0]['url']);
        self::assertSame('fd-shopware-prism/2026-08-25', $http->userAgent(0));
    }

    public function testSettleSendsUcpVersionUserAgent(): void
    {
        $http = new RecordingHttpClient([['success' => true, 'transaction' => '0xabc', 'network' => 'base']]);

        (new PrismHttpClient($http, new PrismResponseParser()))
            ->settle(new PrismConfig('https://prism.example', 'key'), '2026-04-08', ['paymentPayload' => []]);

        self::assertSame('https://prism.example/api/v2/payment/settle', $http->requests[0]['url']);
        self::assertSame('fd-shopware-prism/2026-04-08', $http->userAgent(0));
    }
}

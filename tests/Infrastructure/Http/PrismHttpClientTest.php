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
    public function testPaymentRequirementsPostsToProtocolFreePath(): void
    {
        $http = new RecordingHttpClient([self::body()]);

        $this->client($http)->paymentRequirements(new PrismConfig('https://prism.example', 'key'), '10.00', 'USD', 'https://shop.example/c/1', null);

        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame('https://prism.example/api/v2/merchant/payment-requirements', $http->requests[0]['url']);
        self::assertStringNotContainsString('/ucp/', $http->requests[0]['url']);
    }

    public function testPaymentRequirementsSendsAmountCurrencyAndResource(): void
    {
        $http = new RecordingHttpClient([self::body()]);

        $this->client($http)->paymentRequirements(new PrismConfig('https://prism.example', 'key'), '10.00', 'USD', 'https://shop.example/c/1', 'Mug');

        self::assertSame(
            ['amount' => '10.00', 'currency' => 'USD', 'resource' => ['url' => 'https://shop.example/c/1', 'description' => 'Mug']],
            $http->requests[0]['options']['json'],
        );
    }

    public function testPaymentRequirementsReturnsRawConfig(): void
    {
        $body = self::body();
        $http = new RecordingHttpClient([$body]);

        $result = $this->client($http)->paymentRequirements(new PrismConfig('https://prism.example', 'key'), '10.00', 'USD', 'https://shop.example/c/1', null);

        self::assertSame($body, $result);
    }

    public function testSettlePostsToSettlePath(): void
    {
        $http = new RecordingHttpClient([['success' => true, 'transaction' => '0xabc', 'network' => 'base']]);

        (new PrismHttpClient($http, new PrismResponseParser()))
            ->settle(new PrismConfig('https://prism.example', 'key'), ['paymentPayload' => []]);

        self::assertSame('https://prism.example/api/v2/payment/settle', $http->requests[0]['url']);
    }

    private function client(RecordingHttpClient $http): PrismHttpClient
    {
        return new PrismHttpClient($http, new PrismResponseParser());
    }

    /** @return array<string, mixed> */
    private static function body(): array
    {
        return [
            'x402Version' => 2,
            'resource' => ['url' => 'https://shop.example/c/1'],
            'accepts' => [['scheme' => 'exact']],
        ];
    }
}

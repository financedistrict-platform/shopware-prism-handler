<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Http;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Payment\PrismResponseParser;
use Fd\PrismPayment\Core\Payment\SettleResult;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Ucp\PrismUserAgent;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PrismHttpClient implements PrismGateway
{
    private const SETTLE_PATH = '/api/v2/payment/settle';

    private const QUOTE_TIMEOUT_SECONDS = 10.0;

    private const SETTLE_TIMEOUT_SECONDS = 60.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private PrismResponseParser $parser,
    ) {
    }

    public function paymentRequirements(
        PrismConfig $config,
        string $ucpVersion,
        string $amount,
        string $currency,
        string $resourceUrl,
        ?string $resourceDescription,
    ): array {
        $resource = ['url' => $resourceUrl];
        if (null !== $resourceDescription) {
            $resource['description'] = $resourceDescription;
        }

        $data = $this->post($config, '/api/v2/merchant/ucp/' . rawurlencode($ucpVersion) . '/payment-requirements', [
            'amount' => $amount,
            'currency' => $currency,
            'resource' => $resource,
        ], self::QUOTE_TIMEOUT_SECONDS);

        return $this->parser->paymentRequirementsEntry($data);
    }

    public function settle(PrismConfig $config, array $credential): SettleResult
    {
        $data = $this->post($config, self::SETTLE_PATH, $credential, self::SETTLE_TIMEOUT_SECONDS);

        return $this->parser->settleResult($data);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(PrismConfig $config, string $path, array $body, float $timeout): array
    {
        return $this->send($config, 'POST', $path, $body, $timeout);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(PrismConfig $config, string $method, string $path, ?array $body, float $timeout): array
    {
        $options = [
            'headers' => [
                'X-API-Key' => $config->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => PrismUserAgent::VALUE,
            ],
            'timeout' => $timeout,
        ];
        if (null !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $config->baseUrl . $path, $options);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new PrismApiException(sprintf('Prism %s %s transport error: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new PrismApiException(sprintf('Prism %s %s failed: HTTP %d %s', $method, $path, $status, $raw));
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new PrismApiException(sprintf('Prism %s %s returned non-JSON body: %s', $method, $path, $raw));
        }

        return $decoded;
    }
}

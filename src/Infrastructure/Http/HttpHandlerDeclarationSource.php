<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Http;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Port\HandlerDeclarationSource;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use Fd\PrismPayment\Core\Ucp\HandlerDeclarationParser;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpHandlerDeclarationSource implements HandlerDeclarationSource
{
    private const TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetch(PrismConfig $config, string $ucpVersion): HandlerDeclaration
    {
        $url = rtrim($config->baseUrl, '/') . '/ucp/' . rawurlencode($ucpVersion) . '/handlers';
        $handlers = $this->getJson($url, ['X-API-Key' => $config->apiKey, 'Accept' => 'application/json']);

        $schema = null;
        if (null === HandlerDeclarationParser::declaredInstrumentSchema($handlers, $config->baseUrl)) {
            $schemaUrl = HandlerDeclarationParser::schemaUrl($handlers, $config->baseUrl);
            $schema = $this->getJson($schemaUrl, ['Accept' => 'application/json']);
        }

        return HandlerDeclarationParser::parse($handlers, $schema, $config->baseUrl);
    }

    private function getJson(string $url, array $headers): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new PrismApiException(
                sprintf('Prism GET %s transport error: %s', $url, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new PrismApiException(sprintf('Prism GET %s failed: HTTP %d', $url, $status));
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new PrismApiException(sprintf('Prism GET %s returned a non-JSON body', $url));
        }

        return $decoded;
    }
}

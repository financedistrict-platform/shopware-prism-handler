<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Http;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Port\HandlerDeclarationSource;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use Fd\PrismPayment\Core\Ucp\HandlerDeclarationParser;
use Fd\PrismPayment\Core\Ucp\PrismUserAgent;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Any transport error, non-2xx status, non-JSON body, missing handler entry, or missing/ill-typed
 * field throws {@see PrismApiException} — the provider then falls back to its static default, so a
 * fetch failure never breaks discovery.
 *
 * @internal
 */
final readonly class HttpHandlerDeclarationSource implements HandlerDeclarationSource
{
    private const HANDLERS_PATH = '/api/v2/merchant/ucp/handlers';

    // Discovery is behind a cache (see HandlerDeclarationProvider); this only runs on a cache miss,
    // so a tight ceiling keeps a slow Prism from stalling the profile response.
    private const TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetch(PrismConfig $config, string $ucpVersion): HandlerDeclaration
    {
        $url = rtrim($config->baseUrl, '/') . self::HANDLERS_PATH;
        $userAgent = PrismUserAgent::forUcpVersion($ucpVersion);

        $handlers = $this->getJson($url, $userAgent, ['X-API-Key' => $config->apiKey, 'Accept' => 'application/json']);

        $schema = null;
        if (null === HandlerDeclarationParser::declaredInstrumentSchema($handlers, $config->baseUrl)) {
            $schemaUrl = HandlerDeclarationParser::schemaUrl($handlers, $config->baseUrl);
            $schema = $this->getJson($schemaUrl, $userAgent, ['Accept' => 'application/json']);
        }

        return HandlerDeclarationParser::parse($handlers, $schema, $config->baseUrl);
    }

    private function getJson(string $url, string $userAgent, array $headers): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers + ['User-Agent' => $userAgent],
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

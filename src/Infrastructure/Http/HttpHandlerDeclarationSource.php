<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Http;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Port\HandlerDeclarationSource;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Keyless GET of Prism's public UCP handlers endpoint, mapped to a {@see HandlerDeclaration}.
 *
 * The endpoint is merchant-independent and public (no `X-API-Key`), so discovery stays keyless.
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

    public function fetch(string $gatewayUrl): HandlerDeclaration
    {
        $url = rtrim($gatewayUrl, '/') . self::HANDLERS_PATH;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new PrismApiException(
                sprintf('Prism GET %s transport error: %s', self::HANDLERS_PATH, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new PrismApiException(sprintf('Prism GET %s failed: HTTP %d', self::HANDLERS_PATH, $status));
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new PrismApiException(sprintf('Prism GET %s returned a non-JSON body', self::HANDLERS_PATH));
        }

        return $this->mapEntry($decoded);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mapEntry(array $body): HandlerDeclaration
    {
        $entries = $body[HandlerId::PRISM] ?? null;
        if (!\is_array($entries) || !isset($entries[0]) || !\is_array($entries[0])) {
            throw new PrismApiException(sprintf('Prism handlers response has no "%s" entry', HandlerId::PRISM));
        }
        $entry = $entries[0];

        $id = $entry['id'] ?? null;
        $version = $entry['version'] ?? null;
        $spec = $entry['spec'] ?? null;
        $configSchema = $entry['config_schema'] ?? null;
        $instrumentSchemas = $entry['instrument_schemas'] ?? null;

        if (!\is_string($id) || '' === $id
            || !\is_string($version) || '' === $version
            || !\is_string($spec) || '' === $spec
            || !\is_string($configSchema) || '' === $configSchema
            || !\is_array($instrumentSchemas)
        ) {
            throw new PrismApiException('Prism handler declaration is missing a required field');
        }

        $schemas = array_values(array_filter(
            $instrumentSchemas,
            static fn (mixed $s): bool => \is_string($s) && '' !== $s,
        ));
        if ([] === $schemas) {
            throw new PrismApiException('Prism handler declaration has no instrument schemas');
        }

        return new HandlerDeclaration($id, $version, $spec, $configSchema, $schemas);
    }
}

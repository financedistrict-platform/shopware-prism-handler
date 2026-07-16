<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\HandlerDeclarationSource;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Resolves the Prism-owned handler declaration for UCP discovery, layered so discovery is both
 * fresh and unbreakable:
 *
 *  1. in-request memo — many describe() calls in one request fetch/deserialize at most once;
 *  2. cross-request cache pool (TTL) — Prism is hit at most once per TTL per host, surviving the
 *     PHP-FPM per-request reset (backed by whatever pool the store configured: Redis/APCu/file);
 *  3. static fallback — if Prism is unreachable or malformed, discovery still answers with the
 *     built-in default, cached briefly so a blip doesn't hammer Prism.
 *
 * Sourcing this live (rather than hardcoding id/version/schema URLs) is the point: Prism can evolve
 * the contract and stores pick it up on the next TTL, with no plugin redeploy. The cache key is
 * scoped to the gateway URL, so reconfiguring the gateway (dev↔prod) invalidates naturally.
 *
 * @internal
 */
final class HandlerDeclarationProvider
{
    private const TTL_OK_SECONDS = 3600;

    // Short negative TTL: after a failed fetch we serve the fallback but retry Prism soon, rather
    // than pinning the fallback for a full hour.
    private const TTL_FALLBACK_SECONDS = 60;

    // Built-in default — the declaration this plugin release was built against. Used only when
    // Prism can't be reached; the URLs derive from the configured gateway so they still resolve to
    // the right environment (the same Prism the settle path uses).
    private const FALLBACK_ID = 'x402';

    private const FALLBACK_VERSION = '2026-01-15';

    private ?HandlerDeclaration $memo = null;

    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly HandlerDeclarationSource $source,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function declaration(): HandlerDeclaration
    {
        if (null !== $this->memo) {
            return $this->memo;
        }

        $gateway = rtrim($this->configResolver->gatewayUrl(), '/');
        $key = 'fd_prism.handler_declaration.' . hash('xxh128', $gateway);

        $declaration = $this->cache->get($key, function (ItemInterface $item) use ($gateway): HandlerDeclaration {
            try {
                $live = $this->source->fetch($gateway);
                $item->expiresAfter(self::TTL_OK_SECONDS);

                return $live;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Prism handler declaration fetch failed; serving static fallback for discovery.',
                    ['exception' => $e, 'gateway' => $gateway],
                );
                $item->expiresAfter(self::TTL_FALLBACK_SECONDS);

                return $this->fallback($gateway);
            }
        });

        return $this->memo = $declaration;
    }

    private function fallback(string $gateway): HandlerDeclaration
    {
        return new HandlerDeclaration(
            id: self::FALLBACK_ID,
            version: self::FALLBACK_VERSION,
            spec: $gateway . '/ucp/prism.md',
            configSchema: $gateway . '/ucp/schema.json',
            instrumentSchemas: [$gateway . '/ucp/instrument_schema.json'],
        );
    }
}

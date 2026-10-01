<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\HandlerDeclarationSource;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use Fd\PrismPayment\Core\Ucp\ServedVersion;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

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
    private const FALLBACK_VERSION = '2026-10-07';

    private const DEFAULT_VERSION_KEY = 'default';

    private ?HandlerDeclaration $memo = null;

    private ?string $memoKey = null;

    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly RequestSalesChannelResolver $salesChannelResolver,
        private readonly HandlerDeclarationSource $source,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?RuntimeConfiguration $runtimeConfiguration = null,
    ) {
    }

    public function declaration(RequestContext $context): HandlerDeclaration
    {
        $servedVersion = ServedVersion::resolve(
            $context->runtimeConfiguration?->version,
            $this->runtimeConfiguration?->version,
        );
        $gateway = rtrim($this->configResolver->gatewayUrl(), '/');
        $key = 'fd_prism.handler_declaration.' . hash('xxh128', $gateway . '|' . ($servedVersion ?? self::DEFAULT_VERSION_KEY));

        if (null !== $this->memo && $key === $this->memoKey) {
            return $this->memo;
        }

        $declaration = $this->cache->get($key, function (ItemInterface $item) use ($gateway, $context, $servedVersion): HandlerDeclaration {
            try {
                $salesChannelId = $this->salesChannelResolver->resolve($context);
                $live = $this->source->fetch($this->configResolver->resolve($salesChannelId), $servedVersion);
                $item->expiresAfter(self::TTL_OK_SECONDS);

                return $live;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Prism handler declaration fetch failed; serving static fallback for discovery.',
                    ['exception' => $e, 'gateway' => $gateway, 'ucp_version' => $servedVersion],
                );
                $item->expiresAfter(self::TTL_FALLBACK_SECONDS);

                return $this->fallback($gateway);
            }
        });

        $this->memoKey = $key;

        return $this->memo = $declaration;
    }

    private function fallback(string $gateway): HandlerDeclaration
    {
        return new HandlerDeclaration(
            id: HandlerId::PRISM,
            version: self::FALLBACK_VERSION,
            spec: $gateway . '/ucp/prism.md',
            schema: $gateway . '/ucp/schema.json',
            instrumentSchema: $gateway . '/ucp/instrument_schema.json',
        );
    }
}

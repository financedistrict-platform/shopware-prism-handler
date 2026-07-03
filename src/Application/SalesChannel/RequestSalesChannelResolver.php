<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\SalesChannel;

use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Model\RequestContext;

/**
 * Resolves the Shopware sales-channel id for a UCP request, so the per-sales-channel Prism
 * API key (system_config) can be looked up. Reuses the base extension's domain resolver to
 * stay consistent with how it maps a request host/base URI to a sales channel.
 *
 * @internal
 */
final readonly class RequestSalesChannelResolver
{
    public function __construct(
        private SalesChannelDomainResolver $domainResolver,
    ) {
    }

    public function resolve(RequestContext $context): string
    {
        $baseUri = $context->runtimeConfiguration?->baseUri;
        if (null === $baseUri || '' === $baseUri) {
            $baseUri = 'https://' . $context->host;
        }

        $resolution = $this->domainResolver->resolveByBaseUri($baseUri);
        if (null === $resolution) {
            throw new \RuntimeException(
                sprintf('Could not resolve a Shopware sales channel for UCP host "%s".', $context->host),
            );
        }

        return $resolution->salesChannelId;
    }
}

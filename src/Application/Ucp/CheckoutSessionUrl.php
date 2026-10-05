<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Ucp\Sdk\Model\RequestContext;

final class CheckoutSessionUrl
{
    public static function for(RequestContext $context, string $checkoutId): string
    {
        return self::origin($context) . '/ucp/v1/checkout-sessions/' . $checkoutId;
    }

    private static function origin(RequestContext $context): string
    {
        $baseUri = $context->runtimeConfiguration?->baseUri;
        if (null !== $baseUri && '' !== $baseUri) {
            return rtrim($baseUri, '/');
        }

        $host = $context->host;
        $scheme = str_contains($host, '://')
            ? ''
            : (self::isLocalHost($host) ? 'http://' : 'https://');

        return rtrim($scheme . $host, '/');
    }

    private static function isLocalHost(string $host): bool
    {
        $hostOnly = explode(':', $host)[0];

        return 'localhost' === $hostOnly
            || '127.0.0.1' === $hostOnly
            || str_ends_with($hostOnly, '.localhost');
    }
}

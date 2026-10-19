<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

/**
 * The checkout's resource url in a Prism quote. Prism binds the quote and its settlement to this
 * url, so it is built from the store's APP_URL and the checkout id only — never from the request,
 * which may arrive on any of the store's domains.
 */
final readonly class CheckoutSessionUrl
{
    public function __construct(
        private string $appUrl,
    ) {
    }

    public function for(string $checkoutId): string
    {
        return rtrim($this->appUrl, '/') . '/ucp/v1/checkout-sessions/' . $checkoutId;
    }
}

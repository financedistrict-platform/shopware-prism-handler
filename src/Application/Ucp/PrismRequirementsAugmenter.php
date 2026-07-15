<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Psr\Log\LoggerInterface;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\RequestContext;

/**
 * Injects per-session Prism/x402 payment requirements into the checkout response.
 *
 * For a payable, not-yet-completed checkout we call Prism `ucp/payment-requirements` with
 * the cart total + currency and bind the authorization to the session's continue URL, then
 * surface the returned handler entry verbatim under
 * `payment_handlers["xyz.fd.prism_payment"]` (DoD #2). Prism owns the FX/fee/network/token
 * math; the accepts[] is whatever it returns — we pass it through, including unknown fields
 * (e.g. promotions).
 *
 * @internal
 */
final readonly class PrismRequirementsAugmenter implements CheckoutResponseAugmenterInterface
{
    public function __construct(
        private PrismGateway $client,
        private ConfigResolver $configResolver,
        private RequestSalesChannelResolver $salesChannelResolver,
        private CredentialStore $credentialStore,
        private LoggerInterface $logger,
    ) {
    }

    public function augment(Checkout $checkout, RequestContext $context): Checkout
    {
        if (CheckoutStatus::Canceled === $checkout->status) {
            return $checkout;
        }

        // On a completed checkout, surface the settlement result so the agent gets
        // machine-readable proof of how the order was paid.
        if (CheckoutStatus::Completed === $checkout->status) {
            return $this->withSettlement($checkout);
        }

        $amount = $this->totalAmount($checkout);
        if (null === $amount || $amount <= 0.0) {
            return $checkout;
        }

        $fiatAmount = $this->formatAmount($amount);
        $currency = $checkout->currency;

        // The offer is CART-DRIVEN. While the cart amount/currency is unchanged, serve the exact
        // offer we already recorded — verbatim, with no Prism call — so what the agent signs ==
        // what we store == what we verify at complete (the gateway re-quotes the same cart with
        // sub-cent jitter, which a fresh call would leak into the response and break the binding).
        // A changed amount falls through to a re-quote, which records the new offer and invalidates
        // any captured credential (the prior signature no longer matches the new amount).
        $existing = $this->credentialStore->load($checkout->id);
        if (null !== $existing && $existing->offerMatchesQuote($fiatAmount, $currency)) {
            $entry = $existing->offeredEntry;
            \assert(\is_array($entry));
        } else {
            // The cart amount/currency changed (or this is the first offer). If a Prism credential
            // was already captured, it was signed for the old amount — invalidate it so complete
            // refuses until the agent re-signs for the new amount.
            if (null !== $existing && $existing->hasCredential()) {
                $this->credentialStore->invalidateCredential($checkout->id);
            }

            // The x402 resource binds the authorization to this purchase. Use the canonical
            // session URL (built from the request host) — independent of the optional
            // continue-url template the base extension may not have configured.
            $resourceUrl = $checkout->continueUrl ?? $this->sessionUrl($context, $checkout->id);

            $prismConfig = $this->configResolver->resolve($this->salesChannelResolver->resolve($context));

            try {
                $entry = $this->client->paymentRequirements(
                    $prismConfig,
                    $fiatAmount,
                    $currency,
                    $resourceUrl,
                    $this->describeCart($checkout),
                );
            } catch (PrismApiException $e) {
                // F6: Prism is unreachable / errored. Degrade instead of failing the whole checkout
                // response — omit our handler this round so the agent can still proceed with other
                // methods. A later call (once Prism recovers) re-quotes and surfaces the offer.
                // Fail-closed elsewhere is preserved: with no recorded offer, complete won't settle.
                $this->logger->warning('Prism payment requirements unavailable; omitting handler from checkout response.', [
                    'checkoutId' => $checkout->id,
                    'exception' => $e->getMessage(),
                ]);

                return $checkout;
            }

            // F2 WRITE: persist the offer (full handler entry + the fiat amount/currency it was
            // quoted for) for the binding check at complete.
            $this->credentialStore->recordOffer($checkout->id, $fiatAmount, $currency, $entry);
        }

        $extra = $checkout->extra;
        $existingHandlers = isset($extra['payment_handlers']) && \is_array($extra['payment_handlers'])
            ? $extra['payment_handlers']
            : [];
        $existingHandlers[PrismPaymentHandler::HANDLER_ID] = [$entry];
        $extra['payment_handlers'] = $existingHandlers;

        return $this->withExtra($checkout, $extra);
    }

    private function withSettlement(Checkout $checkout): Checkout
    {
        $record = $this->credentialStore->load($checkout->id);
        if (null === $record || !$record->isSettled()) {
            return $checkout;
        }

        $extra = $checkout->extra;
        $extra['payment'] = [
            'handler_id' => PrismPaymentHandler::HANDLER_ID,
            'status' => 'settled',
            'transaction' => $record->transactionHash,
            'network' => $record->network,
        ];

        return $this->withExtra($checkout, $extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function withExtra(Checkout $checkout, array $extra): Checkout
    {
        return new Checkout(
            id: $checkout->id,
            status: $checkout->status,
            currency: $checkout->currency,
            lineItems: $checkout->lineItems,
            totals: $checkout->totals,
            messages: $checkout->messages,
            links: $checkout->links,
            buyer: $checkout->buyer,
            continueUrl: $checkout->continueUrl,
            expiresAt: $checkout->expiresAt,
            order: $checkout->order,
            extra: $extra,
        );
    }

    private function totalAmount(Checkout $checkout): ?float
    {
        foreach ($checkout->totals as $money) {
            if ($money instanceof Money && 'total' === $money->type) {
                return $money->amount;
            }
        }

        return null;
    }

    // A human-readable purchase summary for the x402 resource (shown by the wallet / on Prism's
    // side). Item titles + quantities, capped so the offer stays compact; null when the cart has
    // no usable titles, which the gateway treats as no description.
    private const MAX_DESCRIPTION_LENGTH = 100;

    private function describeCart(Checkout $checkout): ?string
    {
        $parts = [];
        foreach ($checkout->lineItems as $item) {
            $title = trim($item->title);
            if ('' === $title) {
                continue;
            }

            $parts[] = $item->quantity > 1 ? sprintf('%s ×%d', $title, $item->quantity) : $title;
        }

        if ([] === $parts) {
            return null;
        }

        $description = implode(', ', $parts);
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $description = rtrim(mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH - 1)) . '…';
        }

        return $description;
    }

    private function formatAmount(float $amount): string
    {
        // Major currency units, fixed scale. Prism reads the value in whole currency units
        // and performs the stablecoin conversion itself.
        return number_format($amount, 2, '.', '');
    }

    private function sessionUrl(RequestContext $context, string $checkoutId): string
    {
        $host = $context->host;
        $scheme = str_contains($host, '://')
            ? ''
            : ($this->isLocalHost($host) ? 'http://' : 'https://');

        return rtrim($scheme . $host, '/') . '/ucp/v1/checkout-sessions/' . $checkoutId;
    }

    private function isLocalHost(string $host): bool
    {
        $hostOnly = explode(':', $host)[0];

        return 'localhost' === $hostOnly
            || '127.0.0.1' === $hostOnly
            || str_ends_with($hostOnly, '.localhost');
    }
}

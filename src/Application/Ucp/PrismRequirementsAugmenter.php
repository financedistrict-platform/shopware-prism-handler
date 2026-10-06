<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use Fd\PrismPayment\Core\Ucp\PrismCheckoutEntry;
use Psr\Log\LoggerInterface;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\RequestContext;

/**
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
        private HandlerDeclarationProvider $declarations,
    ) {
    }

    public function augment(Checkout $checkout, RequestContext $context): Checkout
    {
        if (CheckoutStatus::Canceled === $checkout->status) {
            return $checkout;
        }

        if (CheckoutStatus::Completed === $checkout->status) {
            return $this->withSettlement($checkout);
        }

        $amount = $this->totalAmount($checkout);
        if (null === $amount || $amount <= 0.0) {
            return $checkout;
        }

        $fiatAmount = $this->formatAmount($amount);
        $currency = $checkout->currency;

        $existing = $this->credentialStore->load($checkout->id);
        if (null !== $existing && $existing->offerMatchesQuote($fiatAmount, $currency)) {
            $entry = $existing->offeredEntry;
            \assert(\is_array($entry));
        } else {
            if (null !== $existing && $existing->hasCredential()) {
                $this->credentialStore->invalidateCredential($checkout->id);
            }

            $resourceUrl = $checkout->continueUrl ?? CheckoutSessionUrl::for($context, $checkout->id);

            $prismConfig = $this->configResolver->resolve($this->salesChannelResolver->resolve($context));
            $declaration = $this->declaration($context);
            if (null === $declaration) {
                return $checkout;
            }

            try {
                $config = $this->client->paymentRequirements(
                    $prismConfig,
                    $fiatAmount,
                    $currency,
                    $resourceUrl,
                    $this->describeCart($checkout),
                );
            } catch (PrismApiException $e) {
                $this->logger->warning('Prism payment requirements unavailable; omitting handler from checkout response.', [
                    'checkoutId' => $checkout->id,
                    'exception' => $e->getMessage(),
                ]);

                return $checkout;
            }

            $entry = PrismCheckoutEntry::compose($declaration, $config);

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

    private function declaration(RequestContext $context): ?HandlerDeclaration
    {
        try {
            return $this->declarations->declaration($context);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Prism handler declaration unavailable; omitting handler from checkout response.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
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
        return number_format($amount, 2, '.', '');
    }
}

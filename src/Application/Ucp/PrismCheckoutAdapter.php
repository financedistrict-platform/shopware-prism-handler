<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Application\Payment\PrismX402PaymentHandler;
use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Ucp\InstrumentAcceptance;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

final readonly class PrismCheckoutAdapter implements PaymentAwareCheckoutAdapterInterface
{
    private const MAX_CREDENTIAL_BYTES = 8192;

    public function __construct(
        private CheckoutAdapterInterface $inner,
        private CredentialStore $store,
        private PrismGateway $client,
        private ConfigResolver $configResolver,
        private RequestSalesChannelResolver $salesChannelResolver,
        private OrderTransactionStateHandler $transactionStateHandler,
        private EntityRepository $transactionRepository,
        private Connection $connection,
        private SettlementStateMachine $stateMachine,
        private AcceptsMatcher $acceptsMatcher,
    ) {
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        return $this->inner->createCheckout($request, $context);
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->inner->getCheckout($id, $context);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $existing = $this->store->load($request->id);
        if (null !== $existing && !$this->stateMachine->mayChangeCart($existing->status)) {
            throw new ValidationException('This checkout is already paid or being settled and cannot be updated.');
        }

        $payment = $request->payment;
        if (null !== $payment && InstrumentAcceptance::isPrismHandler($payment->handlerId)) {
            $this->store->capture($request->id, $this->validateCredential($payment));
        } elseif (null !== $payment) {
            $this->store->releaseToBase($request->id);
        }

        return $this->inner->updateCheckout($request, $context);
    }

    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout
    {
        $instrument = $this->prismInstrument($request->instruments);

        if (null === $instrument) {
            if ([] === $request->instruments) {
                return $this->completeCheckout($request->id, $context);
            }

            $existing = $this->store->load($request->id);
            if (null !== $existing && $existing->isSettled()) {
                throw new ValidationException('This checkout is already paid with Prism; complete it without changing the payment method.');
            }

            if (null !== $existing) {
                if (!$this->stateMachine->mayCapture($existing->status)) {
                    throw new ValidationException('This checkout is already paid or being settled and cannot be updated.');
                }

                $this->store->releaseToBase($request->id);
            }

            return $this->inner instanceof PaymentAwareCheckoutAdapterInterface
                ? $this->inner->completeCheckoutFromRequest($request, $context)
                : $this->inner->completeCheckout($request->id, $context);
        }

        $credential = $this->validateCredential($instrument);

        $existing = $this->store->load($request->id);
        if (null === $existing || !$existing->isSettled()) {
            if (null !== $existing && !$this->stateMachine->mayCapture($existing->status)) {
                throw new ValidationException('This checkout is already paid or being settled and cannot be updated.');
            }

            $this->store->capture($request->id, $credential);
        }

        return $this->completeCheckout($request->id, $context);
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $record = $this->store->load($id);

        if (null === $record) {
            return $this->inner->completeCheckout($id, $context);
        }

        if ($record->isFailed()) {
            throw new ValidationException(
                'This checkout has a Prism payment that is no longer valid (the cart changed). '
                . 'Submit a new payment before completing.',
            );
        }

        if (!$record->hasCredential()) {
            return $this->inner->completeCheckout($id, $context);
        }

        if (!$record->isSettled()) {
            $this->assertQuoteCoversCart($id, $record, $context);
            $record = $this->settleOnce($id, $record, $context);
        }

        $checkout = $this->inner->completeCheckout($id, $context);
        $this->markOrderPaid($checkout, $record);

        return $checkout;
    }

    private function assertQuoteCoversCart(string $id, PrismSettlementRecord $record, RequestContext $context): void
    {
        $cart = $this->inner->getCheckout($id, $context);
        $total = CheckoutTotal::of($cart);

        if (null === $total || !$record->quotedFor((string) $total, $cart->currency)) {
            throw new ValidationException(
                'The cart changed after this Prism payment was quoted. Fetch the checkout again and submit a new payment.',
            );
        }
    }

    private function settleOnce(string $id, PrismSettlementRecord $record, RequestContext $context): PrismSettlementRecord
    {
        $offered = $record->offeredAccepts();
        $submitted = $record->submittedPaymentRequirements();
        if (null === $offered
            || null === $submitted
            || null === $record->quotedAmount
            || null === $record->quotedCurrency
            || !$this->acceptsMatcher->matches($offered, $submitted)
        ) {
            throw new ValidationException(
                'The submitted payment does not match any offer issued for this checkout.',
            );
        }

        if (!$this->store->claim($id)) {
            $current = $this->store->load($id);
            if (null !== $current && $current->isSettled()) {
                return $current;
            }

            throw new \RuntimeException(sprintf(
                'Settlement for checkout %s is already in progress; not settling again.',
                $id,
            ));
        }

        return $this->settle($id, $record, $context);
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        $record = $this->store->load($id);
        if (null !== $record && $record->isSettled()) {
            throw new ValidationException(
                'This checkout has already been paid and settled on-chain; it cannot be canceled.',
            );
        }

        if (null !== $record && !$this->stateMachine->mayRelease($record->status)) {
            throw new ValidationException(
                'This checkout has a Prism payment being settled on-chain; it cannot be canceled right now.',
            );
        }

        if (null !== $record) {
            $this->store->releaseToBase($id);
        }

        return $this->inner->cancelCheckout($id, $context);
    }

    private function settle(string $sessionId, PrismSettlementRecord $record, RequestContext $context): PrismSettlementRecord
    {
        \assert(null !== $record->credential);
        \assert(null !== $record->quotedAmount && null !== $record->quotedCurrency);

        $config = $this->configResolver->resolve($this->salesChannelResolver->resolve($context));
        $result = $this->client->settle($config, $record->credential);

        if (!$result->success) {
            $this->store->markFailed($sessionId);

            throw new ValidationException(sprintf(
                'Prism declined the payment settlement: %s',
                $result->errorReason ?? 'unknown error',
            ));
        }

        $this->store->markSettled(
            $sessionId,
            $result->transaction,
            $result->network,
            $record->quotedAmount,
            $record->quotedCurrency,
        );

        $refreshed = $this->store->load($sessionId);
        if (null === $refreshed || !$refreshed->isSettled()) {
            throw new \RuntimeException(sprintf(
                'Settlement record for checkout %s is not in the expected settled state after settle.',
                $sessionId,
            ));
        }

        return $refreshed;
    }

    private function markOrderPaid(Checkout $checkout, PrismSettlementRecord $record): void
    {
        $orderId = $checkout->order?->id;
        if (null === $orderId) {
            throw new \RuntimeException('Completed checkout has no order id to attach the settlement to.');
        }

        $this->store->linkOrder($record->checkoutSessionId, $orderId);

        $order = $this->connection->fetchAssociative(
            'SELECT o.amount_total AS amount, c.iso_code AS currency
             FROM `order` o
             JOIN currency c ON c.id = o.currency_id
             WHERE o.id = UNHEX(:orderId) AND o.version_id = UNHEX(:liveVersion)',
            ['orderId' => $orderId, 'liveVersion' => Defaults::LIVE_VERSION],
        );

        if (false === $order || !$record->settledFor((string) $order['amount'], (string) $order['currency'])) {
            throw new ValidationException(sprintf(
                'Order %s does not match the settled Prism payment; it was left unpaid for review.',
                $orderId,
            ));
        }

        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(ot.id)) AS id, sms.technical_name AS state
             FROM order_transaction ot
             JOIN state_machine_state sms ON sms.id = ot.state_id
             WHERE ot.order_id = UNHEX(:orderId)
             ORDER BY ot.created_at ASC
             LIMIT 1',
            ['orderId' => $orderId],
        );

        if (false === $row) {
            throw new \RuntimeException(sprintf('Order %s has no payment transaction to settle.', $orderId));
        }

        $transactionId = (string) $row['id'];
        $shopwareContext = Context::createDefaultContext();

        $awaitingPayment = [
            OrderTransactionStates::STATE_OPEN,
            OrderTransactionStates::STATE_IN_PROGRESS,
            OrderTransactionStates::STATE_AUTHORIZED,
            OrderTransactionStates::STATE_UNCONFIRMED,
            OrderTransactionStates::STATE_REMINDED,
        ];
        if (\in_array($row['state'], $awaitingPayment, true)) {
            $this->transactionStateHandler->paid($transactionId, $shopwareContext);
        }

        $this->transactionRepository->update([[
            'id' => $transactionId,
            'paymentMethodId' => PrismX402PaymentHandler::PAYMENT_METHOD_ID,
        ]], $shopwareContext);
    }

    /** @return array<string, mixed> */
    private function validateCredential(PaymentInstrument $payment): array
    {
        $credential = $payment->credential;

        if (!InstrumentAcceptance::acceptsInstrumentType($payment->type)) {
            throw new ValidationException('Prism payment instrument "type" must be "x402" (or "tokenized", "default", or absent).');
        }

        if ([] === $credential) {
            throw new ValidationException(
                'Prism payment instrument must carry a non-empty "credential" (the wallet\'s signed x402 payment).',
            );
        }

        if (!InstrumentAcceptance::acceptsCredentialType($credential['type'] ?? null)) {
            throw new ValidationException('Prism payment credential "type" must be "x402" when present.');
        }

        $this->assertWithinSizeLimit($credential);

        return $credential;
    }

    private function prismInstrument(array $instruments): ?PaymentInstrument
    {
        foreach ($instruments as $instrument) {
            if (InstrumentAcceptance::isPrismHandler($instrument->handlerId)) {
                return $instrument;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $credential */
    private function assertWithinSizeLimit(array $credential): void
    {
        $encoded = json_encode($credential);
        if (false === $encoded || \strlen($encoded) > self::MAX_CREDENTIAL_BYTES) {
            throw new ValidationException(sprintf(
                'Prism payment credential exceeds the maximum allowed size of %d bytes.',
                self::MAX_CREDENTIAL_BYTES,
            ));
        }
    }
}

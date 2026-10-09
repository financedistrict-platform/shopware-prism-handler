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
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Core\Ucp\InstrumentAcceptance;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
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

    /** Transaction states in which Shopware is still waiting for the payment to arrive. */
    private const AWAITING_PAYMENT = [
        OrderTransactionStates::STATE_OPEN,
        OrderTransactionStates::STATE_IN_PROGRESS,
        OrderTransactionStates::STATE_AUTHORIZED,
        OrderTransactionStates::STATE_UNCONFIRMED,
        OrderTransactionStates::STATE_REMINDED,
    ];

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

    /**
     * Nothing is written until the base has accepted the request. The instrument is validated and
     * the settlement state checked first — both are read-only — but the capture or release lands
     * only once {@see CheckoutAdapterInterface::updateCheckout()} has returned, so a cart the base
     * refuses cannot disturb a credential that is already stored.
     */
    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $payment = $request->payment;
        $credential = null;

        if (null !== $payment && InstrumentAcceptance::isPrismHandler($payment->handlerId)) {
            $existing = $this->store->load($request->id);
            if (null !== $existing && !$this->stateMachine->mayCapture($existing->status)) {
                throw new ValidationException('This checkout is already paid or being settled and cannot be updated.');
            }

            $credential = $this->validateCredential($payment);
        }

        $checkout = $this->inner->updateCheckout($request, $context);

        if (null !== $credential) {
            $this->store->capture($request->id, $credential);
        } elseif (null !== $payment) {
            $this->store->releaseToBase($request->id);
        }

        return $checkout;
    }

    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout
    {
        $instrument = $this->prismInstrument($request->instruments);

        if (null === $instrument) {
            if ([] === $request->instruments) {
                return $this->complete($request->id, $context, null);
            }

            $existing = $this->store->load($request->id);
            $release = false;
            if (null !== $existing && !$existing->isSettled()) {
                if (!$this->stateMachine->mayCapture($existing->status)) {
                    throw new ValidationException('This checkout is already paid or being settled and cannot be updated.');
                }

                $release = true;
            }

            $checkout = $this->inner instanceof PaymentAwareCheckoutAdapterInterface
                ? $this->inner->completeCheckoutFromRequest($request, $context)
                : $this->inner->completeCheckout($request->id, $context);

            if ($release) {
                $this->store->releaseToBase($request->id);
            }

            return $checkout;
        }

        $credential = $this->validateCredential($instrument);

        $existing = $this->store->load($request->id);
        if (null !== $existing && !$existing->isSettled() && !$this->stateMachine->mayCapture($existing->status)) {
            throw new ValidationException('This checkout is already paid or being settled and cannot be updated.');
        }

        return $this->complete($request->id, $context, $credential);
    }

    /**
     * The order comes first. Whether a session can become an order is Shopware's call, so the base
     * places it before any money moves: a rejection settles nothing, and once the order exists the
     * base refuses further cart changes. The payment then follows Shopware's own transaction
     * lifecycle (open → in_progress → paid, or failed).
     */
    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->complete($id, $context, null);
    }

    /**
     * @param array<string, mixed>|null $pending a credential that arrived on this request and has
     *                                          not been stored yet — it is carried in memory so a
     *                                          base that refuses the completion leaves the row as
     *                                          it found it
     */
    private function complete(string $id, RequestContext $context, ?array $pending): Checkout
    {
        $record = $this->store->load($id);

        // A settled or mid-settle row is one the store would refuse to overwrite, so there is
        // nothing to capture and the record is read as it stands.
        $toCapture = null !== $record && !$this->stateMachine->mayCapture($record->status)
            ? null
            : $pending;

        if (null !== $toCapture) {
            $record = $this->capturing($id, $toCapture, $record);
        }

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
            $this->assertCredentialOffered($record);
            $this->assertCartStillMatchesQuote($id, $record, $context);
        }

        $checkout = $this->inner->completeCheckout($id, $context);

        if (null !== $toCapture) {
            $this->store->capture($id, $toCapture);
        }

        $transaction = $this->transaction($checkout);

        if (OrderTransactionStates::STATE_PAID === $transaction['state']) {
            return $checkout;
        }

        $this->assertOrderMatchesQuote($checkout, $record, $transaction);
        $this->store->linkOrder($id, $transaction['orderId']);

        if (!$record->isSettled()) {
            if (OrderTransactionStates::STATE_OPEN === $transaction['state']) {
                $this->transactionStateHandler->process($transaction['id'], Context::createDefaultContext());
            }

            try {
                $record = $this->settleOnce($id, $record, $context);
            } catch (ValidationException $e) {
                $this->abandon($transaction);

                throw $e;
            }
        }

        $this->markPaid($transaction);

        return $checkout;
    }

    /**
     * The record as {@see CredentialStore::capture()} will leave it: the offer kept, the credential
     * replaced, the status reset to pending (a cart that moved earlier must not keep refusing a
     * fresh authorization). Held here, beside the capture it anticipates, so the complete path can
     * check the credential before the order exists and still write it only afterwards.
     *
     * @param array<string, mixed> $credential
     */
    private function capturing(string $id, array $credential, ?PrismSettlementRecord $existing): PrismSettlementRecord
    {
        return new PrismSettlementRecord(
            checkoutSessionId: $id,
            credential: $credential,
            status: SettlementStatus::PENDING,
            transactionHash: null,
            network: null,
            offeredEntry: $existing?->offeredEntry,
            quotedAmount: $existing?->quotedAmount,
            quotedCurrency: $existing?->quotedCurrency,
        );
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

    /** F2: the submitted payment requirements must be one of the accepts we offered for this session. */
    private function assertCredentialOffered(PrismSettlementRecord $record): void
    {
        $offered = $record->offeredAccepts();
        $submitted = $record->submittedPaymentRequirements();
        if (null === $offered
            || null === $submitted
            || !$this->acceptsMatcher->matches($offered, $submitted)
        ) {
            throw new ValidationException(
                'The submitted payment does not match any offer issued for this checkout.',
            );
        }
    }

    /**
     * The cart must still be the one the payment was quoted for. Read-only, and before the order is
     * placed, so a stale authorization costs nothing: no order is created and the session stays open
     * for a fresh quote. The authoritative check is {@see assertOrderMatchesQuote} — Shopware
     * recalculates when it builds the order, so this one can agree and that one still disagree.
     */
    private function assertCartStillMatchesQuote(string $id, PrismSettlementRecord $record, RequestContext $context): void
    {
        $cart = $this->inner->getCheckout($id, $context);
        $total = CheckoutTotal::fiat($cart);

        if (null === $total || !$record->offerMatchesQuote($total, $cart->currency)) {
            throw new ValidationException(
                'The cart changed after this payment was authorized. '
                . 'Request a fresh quote and submit a new payment.',
            );
        }
    }

    /**
     * The order Shopware placed must be the one the payment was quoted for: same fiat total, same
     * currency. Anything else is a cart the signature does not cover.
     *
     * @param array{orderId: string, id: string, state: string} $transaction
     */
    private function assertOrderMatchesQuote(Checkout $checkout, PrismSettlementRecord $record, array $transaction): void
    {
        $total = CheckoutTotal::fiat($checkout);
        if (null === $total || $total !== $record->quotedAmount || $checkout->currency !== $record->quotedCurrency) {
            $this->abandon($transaction);

            throw new ValidationException(
                'The order total does not match the amount this payment was quoted for. '
                . 'The order was placed but not paid, and this checkout can no longer be changed; '
                . 'start a new checkout session.',
            );
        }
    }

    private function settleOnce(string $id, PrismSettlementRecord $record, RequestContext $context): PrismSettlementRecord
    {
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

    private function settle(string $sessionId, PrismSettlementRecord $record, RequestContext $context): PrismSettlementRecord
    {
        \assert(null !== $record->credential);

        $config = $this->configResolver->resolve($this->salesChannelResolver->resolve($context));
        $result = $this->client->settle($config, $record->credential);

        if (!$result->success) {
            $this->store->markFailed($sessionId);

            throw new ValidationException(sprintf(
                'Prism declined the payment settlement: %s',
                $result->errorReason ?? 'unknown error',
            ));
        }

        $this->store->markSettled($sessionId, $result->transaction, $result->network);

        $refreshed = $this->store->load($sessionId);
        if (null === $refreshed || !$refreshed->isSettled()) {
            throw new \RuntimeException(sprintf(
                'Settlement record for checkout %s is not in the expected settled state after settle.',
                $sessionId,
            ));
        }

        return $refreshed;
    }

    /**
     * The order's payment transaction, as Shopware holds it right after placement.
     *
     * @return array{orderId: string, id: string, state: string}
     */
    private function transaction(Checkout $checkout): array
    {
        $orderId = $checkout->order?->id;
        if (null === $orderId) {
            throw new \RuntimeException('Completed checkout has no order id to attach the settlement to.');
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

        return ['orderId' => $orderId, 'id' => (string) $row['id'], 'state' => (string) $row['state']];
    }

    /** @param array{orderId: string, id: string, state: string} $transaction */
    private function markPaid(array $transaction): void
    {
        $shopwareContext = Context::createDefaultContext();

        if (\in_array($transaction['state'], self::AWAITING_PAYMENT, true)) {
            $this->transactionStateHandler->paid($transaction['id'], $shopwareContext);
        }

        $this->transactionRepository->update([[
            'id' => $transaction['id'],
            'paymentMethodId' => PrismX402PaymentHandler::PAYMENT_METHOD_ID,
        ]], $shopwareContext);
    }

    /** @param array{orderId: string, id: string, state: string} $transaction */
    private function abandon(array $transaction): void
    {
        if (\in_array($transaction['state'], self::AWAITING_PAYMENT, true)) {
            $this->transactionStateHandler->fail($transaction['id'], Context::createDefaultContext());
        }
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

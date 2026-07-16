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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * Decorates the base SwagAgenticCommerce checkout adapter to drive Prism/x402 settlement
 * without forking it. Two seams matter:
 *
 *  - updateCheckout: the agent's signed x402 credential arrives here; we capture it.
 *  - completeCheckout: we settle on-chain via Prism, then let the base place the order and
 *    mark its payment transaction paid, carrying the on-chain tx hash.
 *
 * Settlement happens BEFORE the order is placed, so a failed settlement leaves no order and
 * the agent can retry. It is once-only: a re-complete sees status=settled and never re-settles
 * (matching the base's own idempotent return of the prior order).
 *
 * @internal
 */
final readonly class PrismCheckoutAdapter implements CheckoutAdapterInterface
{
    // F4: cap the whole agent-supplied credential. A real x402 credential is ~1.5 KB; this is the
    // primary gate (clean 422 + truncation-proof) behind the VARCHAR(8192) column backstop.
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
        $payment = $request->payment;
        if (null !== $payment && PrismPaymentHandler::HANDLER_ID === $payment->handlerId) {
            // F0: refuse to (re)capture against a settled OR mid-settle (`settling`) session. A clean
            // 422 to the agent instead of silently reverting a paid/in-flight settle back to pending
            // (which would let a second complete re-claim and settle again). capture()'s SQL is also
            // guarded, so this holds even under a concurrent settle.
            $existing = $this->store->load($request->id);
            if (null !== $existing && !$this->stateMachine->mayCapture($existing->status)) {
                throw new ValidationException('This checkout is already paid or being settled and cannot be updated.');
            }

            $this->store->capture($request->id, $this->validateCredential($payment));
        } elseif (null !== $payment) {
            // The agent selected a DIFFERENT payment method — we only answer to the Prism handler.
            // Release any prior Prism claim (drop the credential, back to pending) so complete
            // defers to the base flow for their chosen method instead of settling or blocking.
            $this->store->releaseToBase($request->id);
        }

        return $this->inner->updateCheckout($request, $context);
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $record = $this->store->load($id);

        // Never engaged for this session — defer entirely to the base flow.
        if (null === $record) {
            return $this->inner->completeCheckout($id, $context);
        }

        // Prism WAS engaged but the credential is no longer usable (the cart changed after signing,
        // or a prior settle failed). We own this checkout's payment, so refuse with a clean 422
        // rather than let the base place an unpaid order — the agent must submit a fresh signed
        // payment (or select another method, which releases our claim via updateCheckout).
        if ($record->isFailed()) {
            throw new ValidationException(
                'This checkout has a Prism payment that is no longer valid (the cart changed). '
                . 'Submit a new payment before completing.',
            );
        }

        // An offer-only row with no credential — the agent never chose Prism here. Defer to base.
        if (!$record->hasCredential()) {
            return $this->inner->completeCheckout($id, $context);
        }

        if (!$record->isSettled()) {
            $record = $this->settleOnce($id, $record, $context);
        }

        // Settled: place the order (idempotent in the base) and mark its transaction paid.
        $checkout = $this->inner->completeCheckout($id, $context);
        $this->markOrderPaid($checkout, $record);

        return $checkout;
    }

    /**
     * Verify the payment binding (F2) and settle exactly once (F1). Returns the settled record.
     */
    private function settleOnce(string $id, PrismSettlementRecord $record, RequestContext $context): PrismSettlementRecord
    {
        // F2 (immediately before the claim): the submitted paymentRequirements MUST be one we
        // offered for this session. Fail-closed — a missing/empty offer set refuses to settle.
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

        // F1: atomically claim pending -> settling; only the winner performs the on-chain settle.
        if (!$this->store->claim($id)) {
            // Lost the claim: a concurrent (or prior) complete is settling / has settled.
            $current = $this->store->load($id);
            if (null !== $current && $current->isSettled()) {
                return $current; // settled by the winner — proceed idempotently, never re-settle
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
        // Forward-only (same invariant as F0/D13): a Prism-settled checkout is terminal. The funds
        // moved on-chain and the order is placed + paid, so refuse to cancel it with a clean 422
        // rather than let the base flip the session to "canceled".
        $record = $this->store->load($id);
        if (null !== $record && $record->isSettled()) {
            throw new ValidationException(
                'This checkout has already been paid and settled on-chain; it cannot be canceled.',
            );
        }

        // Not settled: drop any captured (but un-settled) credential before deferring the cancel to
        // the base. Otherwise the credential would linger on the now-canceled session and a later
        // complete would settle funds on-chain against a checkout that can no longer place an order
        // (a "paid, no order" desync). Releasing it makes complete defer cleanly to the base.
        if (null !== $record) {
            $this->store->releaseToBase($id);
        }

        return $this->inner->cancelCheckout($id, $context);
    }

    private function settle(string $sessionId, PrismSettlementRecord $record, RequestContext $context): PrismSettlementRecord
    {
        // Guaranteed non-null by the hasCredential() gate + the F2 check before the claim.
        \assert(null !== $record->credential);

        $config = $this->configResolver->resolve($this->salesChannelResolver->resolve($context));
        $result = $this->client->settle($config, $record->credential);

        if (!$result->success) {
            $this->store->markFailed($sessionId);

            // Relay Prism's own rejection verdict to the agent as a clean 422 (ValidationException)
            // instead of an opaque 500. We do not interpret the credential — Prism owns all
            // x402/token/chain validation (expiry, funds, signature, nonce) — we surface its
            // machine-readable errorReason verbatim so the agent can act (e.g. re-sign an expired
            // authorization, or choose another asset on insufficient funds).
            throw new ValidationException(sprintf(
                'Prism declined the payment settlement: %s',
                $result->errorReason ?? 'unknown error',
            ));
        }

        // Persist BEFORE placing the order so a crash cannot cause a second on-chain settle.
        $this->store->markSettled($sessionId, $result->transaction, $result->network);

        // markSettled only completes a `settling` row; if the row is not settled afterwards, state
        // changed under us — refuse to place an order against an unconfirmed settlement rather than
        // attribute it with a missing tx.
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

        // The row is keyed by checkout session; link it to the order so admin can look it up.
        $this->store->linkOrder($record->checkoutSessionId, $orderId);

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

        // Drive the transaction to paid ONLY from a state that is still awaiting payment — the base's
        // freshly-placed transaction is `open`. Never re-drive it otherwise: on an idempotent
        // re-complete a merchant may have cancelled/refunded the payment in the admin, and forcing it
        // back to paid would either silently override that (`cancelled -> paid` is a valid transition)
        // or throw (`refunded` has no path to paid → a 500). We settle on-chain exactly once; the
        // transaction's later lifecycle belongs to the merchant. `open` here also covers crash
        // recovery — a prior complete that settled + placed the order but died before marking paid is
        // finished by a retry.
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

        // The base places the order on the sales-channel default; reattribute to our method
        // (stable id, so idempotent on re-complete).
        $this->transactionRepository->update([[
            'id' => $transactionId,
            'paymentMethodId' => PrismX402PaymentHandler::PAYMENT_METHOD_ID,
        ]], $shopwareContext);
    }

    /**
     * Validate and return the agent's x402 credential as ONE opaque object. We deliberately do NOT
     * reach in for `paymentPayload`/`paymentRequirements`: understanding x402 is the wallet's and
     * Prism's job, not this relay's. We only assert the credential is present and within the size
     * bound (F4), then store + forward it verbatim.
     *
     * @return array<string, mixed>
     */
    private function validateCredential(PaymentInstrument $payment): array
    {
        $credential = $payment->credential;

        if ([] === $credential) {
            // L-1: a missing credential is the agent's input error — clean 422 (ValidationException)
            // rather than a bare RuntimeException that surfaces as a 500.
            throw new ValidationException(
                'Prism payment instrument must carry a non-empty "credential" (the wallet\'s signed x402 payment).',
            );
        }

        $this->assertWithinSizeLimit($credential);

        return $credential;
    }

    /**
     * F4: reject an oversized credential at the boundary with a clean 422, before it is ever stored —
     * measured on the same JSON encoding the store persists.
     *
     * @param array<string, mixed> $credential
     */
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

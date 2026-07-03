<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Payment;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Shopware payment method backing Prism/x402 UCP orders.
 *
 * Settlement does NOT happen here: an agent pays via the UCP checkout flow and
 * `PrismCheckoutAdapter` settles on-chain through Prism and marks the transaction paid. This
 * handler exists so the resulting order carries a correct, dedicated payment method ("Prism
 * x402") instead of the sales-channel default — and so the method is a first-class,
 * referenceable Shopware entity.
 *
 * `pay()` is NEVER the payment path here (the base places the order on the sales-channel default
 * method, then `PrismCheckoutAdapter` reassigns the already-paid transaction to this one), so it
 * deliberately REFUSES. A silent no-op would let this method finalize an UNPAID order if it were
 * ever selected outside the UCP flow (e.g. mistakenly assigned to a sales channel); declining
 * makes that impossible — it fails loudly instead.
 *
 * @internal
 */
final class PrismX402PaymentHandler extends AbstractPaymentHandler
{
    /** Stable id so install/activate and order assignment all reference the same method. */
    public const PAYMENT_METHOD_ID = 'b1f9e4c7a8d24e3fae6b9c1d2e3f4a5b';

    public const TECHNICAL_NAME = 'payment_fd_prism_x402';

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // Deliberate divergence from AbstractPaymentHandler's optional capabilities: we advertise
        // NONE of them (refund / recurring). An x402 on-chain settlement is final and managed by
        // Prism, not by the Shopware payment lifecycle, so there is no Shopware-side refund or
        // recurring path to support. Returning false for every type is intentional, not an omission.
        return false;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct,
    ): ?RedirectResponse {
        // Reaching pay() means this method was selected to charge an order directly — which never
        // happens in the UCP flow (settlement is out-of-band; the transaction is reassigned to this
        // method already paid). Refuse rather than no-op into an unpaid order.
        throw PaymentException::syncProcessInterrupted(
            $transaction->getOrderTransactionId(),
            'Prism (x402 on-chain) settles via the agentic UCP checkout flow and cannot be charged '
            . 'directly. Do not assign this payment method to a sales channel.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Advertises the Prism/x402 payment handler in the UCP platform profile
 * (`GET /.well-known/ucp` → `payment_handlers["xyz.fd.prism_payment"]`).
 *
 * The descriptor is static: id/version are protocol-level constants (they track Prism's
 * x402 handler and are bumped per plugin release), and the public spec/schema URLs are
 * derived from the resolved gateway. Discovery therefore needs no API key and makes no live
 * Prism call — it cannot be broken by Prism downtime or an unconfigured merchant key. The
 * per-session, merchant-specific data (accepts) is sourced live by the requirements augmenter.
 *
 * @internal
 */
final readonly class PrismPaymentHandler implements PaymentHandlerInterface
{
    /** Externally-stable UCP handler id (Prism's), independent of the plugin name. */
    public const HANDLER_ID = HandlerId::PRISM;

    /** Prism x402 handler instance id + version this release targets. */
    private const INSTANCE_ID = 'x402';

    private const HANDLER_VERSION = '2026-01-15';

    public function __construct(
        private ConfigResolver $configResolver,
    ) {
    }

    public function id(): string
    {
        return self::HANDLER_ID;
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        $gateway = $this->configResolver->gatewayUrl();

        return new PaymentHandlerDescriptor(
            id: self::INSTANCE_ID,
            name: self::HANDLER_ID,
            version: self::HANDLER_VERSION,
            specUrl: $gateway . '/ucp/prism.md',
            configSchema: $gateway . '/ucp/schema.json',
            instrumentSchemas: [],
            config: [],
        );
    }

    /**
     * @return array{paymentMethodId: string, token: string, displayLast4?: string, displayBrand?: string}
     */
    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        // Settlement is driven by the checkout-adapter decorator (capture on update,
        // settle on complete), not by instrument preparation. Nothing to prepare here.
        return [
            'paymentMethodId' => '',
            'token' => '',
        ];
    }

    public function supportsTokenization(): bool
    {
        // REQUIRED: the capability gate (CapabilityFilteringProfileContributor) only keeps
        // payment_handlers when a handler reports tokenization support AND the sales channel
        // has the payment_tokenization capability enabled.
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
    {
        // The Prism/x402 settlement runs inside the checkout flow (update → complete), not
        // via the separate token-exchange endpoint. Reaching here means a caller used
        // token-exchange against this handler — unsupported. Fail fast rather than fake a token.
        throw new \LogicException(
            'xyz.fd.prism_payment settles during the checkout complete step, not via token-exchange.',
        );
    }
}

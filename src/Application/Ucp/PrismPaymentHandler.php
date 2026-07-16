<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Fd\PrismPayment\Core\Ucp\HandlerId;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Advertises the Prism/x402 payment handler in the UCP platform profile
 * (`GET /.well-known/ucp` → `payment_handlers["xyz.fd.prism_payment"]`).
 *
 * The id/version and the spec/schema URLs are Prism's to declare — this handler does not invent
 * them, it re-advertises what Prism publishes at its public handlers endpoint. Resolving that
 * declaration (live-with-cache, static fallback) is delegated to {@see HandlerDeclarationProvider};
 * this class only maps it into the SDK descriptor and keeps the locally-owned registration id.
 * Discovery still needs no API key (the source endpoint is public) and never breaks (fallback). The
 * per-session, merchant-specific data (accepts) is sourced live by the requirements augmenter.
 *
 * @internal
 */
final readonly class PrismPaymentHandler implements PaymentHandlerInterface
{
    /** Externally-stable UCP handler id (Prism's), independent of the plugin name. */
    public const HANDLER_ID = HandlerId::PRISM;

    public function __construct(
        private HandlerDeclarationProvider $declarations,
    ) {
    }

    public function id(): string
    {
        return self::HANDLER_ID;
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        $declaration = $this->declarations->declaration();

        return new PaymentHandlerDescriptor(
            id: $declaration->id,
            name: self::HANDLER_ID,
            version: $declaration->version,
            specUrl: $declaration->spec,
            configSchema: $declaration->configSchema,
            instrumentSchemas: $declaration->instrumentSchemas,
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

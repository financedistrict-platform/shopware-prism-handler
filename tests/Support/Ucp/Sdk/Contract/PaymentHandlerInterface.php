<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

interface PaymentHandlerInterface
{
    public function id(): string;

    public function describe(RequestContext $context): PaymentHandlerDescriptor;

    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array;

    public function supportsTokenization(): bool;

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array;
}

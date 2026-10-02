<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Ucp\ServedVersion;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/**
 * @internal
 */
final readonly class UcpVersionResolver
{
    public function __construct(
        private ?RuntimeConfiguration $runtimeConfiguration = null,
    ) {
    }

    public function resolve(RequestContext $context): string
    {
        $version = ServedVersion::resolve($context->runtimeConfiguration?->version, $this->runtimeConfiguration?->version);
        if (null === $version) {
            throw new PrismApiException('The served UCP version is unknown: neither the request nor the SDK configuration carries one.');
        }

        return $version;
    }
}

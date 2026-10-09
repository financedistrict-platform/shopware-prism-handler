<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

final class SalesChannelResolution
{
    public function __construct(
        public readonly string $salesChannelId,
    ) {
    }
}

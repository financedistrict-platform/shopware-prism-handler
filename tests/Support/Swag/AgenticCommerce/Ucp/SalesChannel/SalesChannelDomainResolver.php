<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

class SalesChannelDomainResolver
{
    public function resolveByBaseUri(string $baseUri): ?object
    {
        return (object) ['salesChannelId' => 'sales-channel'];
    }
}

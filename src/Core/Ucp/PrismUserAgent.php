<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

final class PrismUserAgent
{
    private const PRODUCT = 'fd-shopware-prism';

    public static function forUcpVersion(string $ucpVersion): string
    {
        return self::PRODUCT . '/' . $ucpVersion;
    }
}

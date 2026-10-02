<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

final class ServedVersion
{
    public static function resolve(?string $contextVersion, ?string $configuredVersion): ?string
    {
        foreach ([$contextVersion, $configuredVersion] as $version) {
            if (null !== $version && '' !== $version) {
                return $version;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

final class PluginVersion
{
    public const USER_AGENT_PRODUCT = 'fd-shopware-prism';

    private const UNKNOWN = 'unknown';

    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ??= self::read(\dirname(__DIR__, 3) . '/composer.json');
    }

    public static function userAgent(): string
    {
        return self::USER_AGENT_PRODUCT . '/' . self::current();
    }

    public static function read(string $composerJsonPath): string
    {
        $raw = is_file($composerJsonPath) ? file_get_contents($composerJsonPath) : false;
        $manifest = false === $raw ? null : json_decode($raw, true);
        $version = \is_array($manifest) ? ($manifest['version'] ?? null) : null;

        return \is_string($version) && '' !== $version ? $version : self::UNKNOWN;
    }
}

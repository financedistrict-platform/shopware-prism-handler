<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use Fd\PrismPayment\Core\Ucp\PluginVersion;
use PHPUnit\Framework\TestCase;

final class PluginVersionTest extends TestCase
{
    public function testReadsVersionFromComposerManifest(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame($manifest['version'], PluginVersion::current());
    }

    public function testUserAgentNamesThePluginAndItsVersion(): void
    {
        self::assertSame('fd-shopware-prism/' . PluginVersion::current(), PluginVersion::userAgent());
    }

    public function testUnreadableManifestYieldsUnknown(): void
    {
        self::assertSame('unknown', PluginVersion::read(__DIR__ . '/missing-composer.json'));
    }
}

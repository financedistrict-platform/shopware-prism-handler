<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use Fd\PrismPayment\Core\Ucp\ServedVersion;
use PHPUnit\Framework\TestCase;

final class ServedVersionTest extends TestCase
{
    public function testRequestRuntimeVersionWins(): void
    {
        self::assertSame('2026-08-25', ServedVersion::resolve('2026-08-25', '2026-04-08'));
    }

    public function testDiscoveryFallsBackToConfiguredVersion(): void
    {
        self::assertSame('2026-04-08', ServedVersion::resolve(null, '2026-04-08'));
    }

    public function testEmptyRequestVersionFallsBackToConfiguredVersion(): void
    {
        self::assertSame('2026-04-08', ServedVersion::resolve('', '2026-04-08'));
    }

    public function testNoVersionKnownMeansPrismDefault(): void
    {
        self::assertNull(ServedVersion::resolve(null, null));
    }
}

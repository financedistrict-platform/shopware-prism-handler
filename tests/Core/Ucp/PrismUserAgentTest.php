<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use Fd\PrismPayment\Core\Ucp\PrismUserAgent;
use PHPUnit\Framework\TestCase;

final class PrismUserAgentTest extends TestCase
{
    public function testUserAgentMatchesPackageVersion(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true);

        self::assertSame('fd-shopware-prism/' . $composer['version'], PrismUserAgent::VALUE);
    }
}

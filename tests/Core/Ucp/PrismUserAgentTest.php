<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use Fd\PrismPayment\Core\Ucp\PrismUserAgent;
use PHPUnit\Framework\TestCase;

final class PrismUserAgentTest extends TestCase
{
    public function testUserAgentCarriesTheUcpVersion(): void
    {
        self::assertSame('fd-shopware-prism/2026-08-25', PrismUserAgent::forUcpVersion('2026-08-25'));
    }
}

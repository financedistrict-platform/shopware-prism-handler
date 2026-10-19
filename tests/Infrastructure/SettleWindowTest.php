<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Infrastructure;

use Fd\PrismPayment\Infrastructure\Http\PrismHttpClient;
use Fd\PrismPayment\Infrastructure\Persistence\DbalCredentialStore;
use PHPUnit\Framework\TestCase;

final class SettleWindowTest extends TestCase
{
    public function testAnInFlightSettleCallIsNeverTakenOverByAResumedClaim(): void
    {
        $window = (new \ReflectionClassConstant(DbalCredentialStore::class, 'SETTLE_WINDOW_SECONDS'))->getValue();
        $timeout = (new \ReflectionClassConstant(PrismHttpClient::class, 'SETTLE_TIMEOUT_SECONDS'))->getValue();

        self::assertGreaterThan($timeout, $window);
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Application\Ucp\UcpVersionResolver;
use Fd\PrismPayment\Core\Exception\PrismApiException;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

final class UcpVersionResolverTest extends TestCase
{
    public function testRequestVersionWins(): void
    {
        $resolver = new UcpVersionResolver(new RuntimeConfiguration('2026-04-08'));

        self::assertSame('2026-08-25', $resolver->resolve(new RequestContext(new RuntimeConfiguration('2026-08-25'))));
    }

    public function testFallsBackToSdkConfiguration(): void
    {
        $resolver = new UcpVersionResolver(new RuntimeConfiguration('2026-04-08'));

        self::assertSame('2026-04-08', $resolver->resolve(new RequestContext()));
    }

    public function testUnknownVersionIsAConfigurationFaultNotAPrismFailure(): void
    {
        $resolver = new UcpVersionResolver();

        try {
            $resolver->resolve(new RequestContext());
            self::fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            self::assertNotInstanceOf(PrismApiException::class, $e);
        }
    }
}

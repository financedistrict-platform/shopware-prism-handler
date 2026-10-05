<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Application\Ucp\CheckoutSessionUrl;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

final class CheckoutSessionUrlTest extends TestCase
{
    public function testKeepsTheSchemeAndNonDefaultPortOfTheStore(): void
    {
        $context = new RequestContext(new RuntimeConfiguration('2026-08-25', 'http://localhost:8081'), 'localhost');

        self::assertSame('http://localhost:8081/ucp/v1/checkout-sessions/abc', CheckoutSessionUrl::for($context, 'abc'));
    }

    public function testDropsATrailingSlashOfTheBaseUri(): void
    {
        $context = new RequestContext(new RuntimeConfiguration('2026-08-25', 'https://shop.example/'), 'shop.example');

        self::assertSame('https://shop.example/ucp/v1/checkout-sessions/abc', CheckoutSessionUrl::for($context, 'abc'));
    }

    public function testFallsBackToHttpsOnTheRequestHost(): void
    {
        self::assertSame(
            'https://shop.example/ucp/v1/checkout-sessions/abc',
            CheckoutSessionUrl::for(new RequestContext(null, 'shop.example'), 'abc'),
        );
    }

    public function testFallsBackToHttpOnALocalHost(): void
    {
        self::assertSame(
            'http://localhost/ucp/v1/checkout-sessions/abc',
            CheckoutSessionUrl::for(new RequestContext(new RuntimeConfiguration('2026-08-25', ''), 'localhost'), 'abc'),
        );
    }
}

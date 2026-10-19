<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Application\Ucp\CheckoutSessionUrl;
use PHPUnit\Framework\TestCase;

final class CheckoutSessionUrlTest extends TestCase
{
    public function testKeepsTheSchemeAndPortOfTheStore(): void
    {
        self::assertSame(
            'http://localhost:8081/ucp/v1/checkout-sessions/abc',
            (new CheckoutSessionUrl('http://localhost:8081'))->for('abc'),
        );
    }

    public function testDropsATrailingSlashOfTheStoreUrl(): void
    {
        self::assertSame(
            'https://shop.example/ucp/v1/checkout-sessions/abc',
            (new CheckoutSessionUrl('https://shop.example/'))->for('abc'),
        );
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use PHPUnit\Framework\TestCase;

final class HandlerDeclarationFallbackTest extends TestCase
{
    public function testUrlsCarryTheServedVersion(): void
    {
        $declaration = HandlerDeclaration::forGateway('https://prism.example/', '2026-10-07', '2026-04-08');

        self::assertSame(HandlerId::PRISM, $declaration->id);
        self::assertSame('2026-10-07', $declaration->version);
        self::assertSame('https://prism.example/ucp/2026-04-08/prism.md', $declaration->spec);
        self::assertSame('https://prism.example/ucp/2026-04-08/schema.json', $declaration->schema);
        self::assertSame('https://prism.example/ucp/2026-04-08/instrument_schema.json', $declaration->instrumentSchema);
    }
}

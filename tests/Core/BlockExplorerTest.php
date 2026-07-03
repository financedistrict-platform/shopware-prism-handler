<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core;

use Fd\PrismPayment\Core\BlockExplorer;
use PHPUnit\Framework\TestCase;

final class BlockExplorerTest extends TestCase
{
    private BlockExplorer $explorer;

    protected function setUp(): void
    {
        $this->explorer = new BlockExplorer();
    }

    public function testTxUrlForMappedNetwork(): void
    {
        self::assertSame(
            'https://testnet.bscscan.com/tx/0xaaff',
            $this->explorer->txUrl('eip155:97', '0xaaff'),
        );
    }

    public function testTxUrlIsNullForUnmappedNetwork(): void
    {
        self::assertNull($this->explorer->txUrl('eip155:999999', '0xaaff'));
    }

    public function testReferenceUsesExplorerUrlWhenMapped(): void
    {
        self::assertSame(
            'https://basescan.org/tx/0xbeef',
            $this->explorer->reference('eip155:8453', '0xbeef'),
        );
    }

    public function testReferenceFallsBackToNetworkAndHashWhenUnmapped(): void
    {
        self::assertSame(
            'eip155:999999: 0xbeef',
            $this->explorer->reference('eip155:999999', '0xbeef'),
        );
    }
}

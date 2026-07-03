<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core;

/**
 * Resolves a block-explorer transaction URL from a CAIP-2 network id.
 *
 * `txUrl()` returns null for unmapped networks (we never fabricate a link). `reference()`
 * always returns something displayable: the explorer URL when known, otherwise a plain
 * "network: txHash" so the operator is never left with a dead end.
 *
 * @internal
 */
final class BlockExplorer
{
    /** CAIP-2 network id => explorer tx URL prefix. */
    private const TX_URL_PREFIX = [
        'eip155:1' => 'https://etherscan.io/tx/',          // Ethereum
        'eip155:11155111' => 'https://sepolia.etherscan.io/tx/', // Ethereum Sepolia
        'eip155:56' => 'https://bscscan.com/tx/',          // BSC
        'eip155:97' => 'https://testnet.bscscan.com/tx/',  // BSC testnet
        'eip155:8453' => 'https://basescan.org/tx/',       // Base
        'eip155:84532' => 'https://sepolia.basescan.org/tx/', // Base Sepolia
        'eip155:42161' => 'https://arbiscan.io/tx/',       // Arbitrum One
        'eip155:421614' => 'https://sepolia.arbiscan.io/tx/', // Arbitrum Sepolia
    ];

    public function txUrl(string $network, string $transactionHash): ?string
    {
        $prefix = self::TX_URL_PREFIX[$network] ?? null;
        if (null === $prefix) {
            return null;
        }

        return $prefix . $transactionHash;
    }

    /**
     * Always-displayable reference: explorer URL if the network is mapped, otherwise a
     * plain "network: txHash" fallback.
     */
    public function reference(string $network, string $transactionHash): string
    {
        return $this->txUrl($network, $transactionHash) ?? sprintf('%s: %s', $network, $transactionHash);
    }
}

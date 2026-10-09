<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;

final class InMemorySettlementStore implements CredentialStore
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function recordOffer(string $sessionId, string $quotedAmount, string $quotedCurrency, array $offeredEntry): void
    {
        $row = $this->rows[$sessionId] ?? $this->emptyRow();
        if (SettlementStatus::SETTLED === $row['status']) {
            return;
        }

        $row['offeredEntry'] = $offeredEntry;
        $row['quotedAmount'] = $quotedAmount;
        $row['quotedCurrency'] = $quotedCurrency;
        $this->rows[$sessionId] = $row;
    }

    public function invalidateCredential(string $sessionId): void
    {
        if (!isset($this->rows[$sessionId]) || SettlementStatus::SETTLED === $this->rows[$sessionId]['status']) {
            return;
        }

        $this->rows[$sessionId]['status'] = SettlementStatus::FAILED;
        $this->rows[$sessionId]['credential'] = null;
    }

    public function releaseToBase(string $sessionId): void
    {
        if (!isset($this->rows[$sessionId]) || $this->isLocked($sessionId)) {
            return;
        }

        $this->rows[$sessionId]['status'] = SettlementStatus::PENDING;
        $this->rows[$sessionId]['credential'] = null;
    }

    public function capture(string $sessionId, array $credential): void
    {
        $row = $this->rows[$sessionId] ?? $this->emptyRow();
        if (\in_array($row['status'], [SettlementStatus::SETTLED, SettlementStatus::SETTLING], true)) {
            return;
        }

        $row['credential'] = $credential;
        $row['status'] = SettlementStatus::PENDING;
        $this->rows[$sessionId] = $row;
    }

    public function claim(string $sessionId): bool
    {
        if (SettlementStatus::PENDING !== ($this->rows[$sessionId]['status'] ?? null)) {
            return false;
        }

        $this->rows[$sessionId]['status'] = SettlementStatus::SETTLING;

        return true;
    }

    public function load(string $sessionId): ?PrismSettlementRecord
    {
        $row = $this->rows[$sessionId] ?? null;
        if (null === $row) {
            return null;
        }

        return new PrismSettlementRecord(
            checkoutSessionId: $sessionId,
            credential: $row['credential'],
            status: $row['status'],
            transactionHash: $row['transactionHash'],
            network: $row['network'],
            offeredEntry: $row['offeredEntry'],
            quotedAmount: $row['quotedAmount'],
            quotedCurrency: $row['quotedCurrency'],
            settledAmount: $row['settledAmount'],
            settledCurrency: $row['settledCurrency'],
        );
    }

    public function markSettled(string $sessionId, string $transactionHash, string $network, string $settledAmount, string $settledCurrency): void
    {
        if (SettlementStatus::SETTLING !== ($this->rows[$sessionId]['status'] ?? null)) {
            return;
        }

        $this->rows[$sessionId]['status'] = SettlementStatus::SETTLED;
        $this->rows[$sessionId]['transactionHash'] = $transactionHash;
        $this->rows[$sessionId]['network'] = $network;
        $this->rows[$sessionId]['settledAmount'] = $settledAmount;
        $this->rows[$sessionId]['settledCurrency'] = $settledCurrency;
    }

    public function markFailed(string $sessionId): void
    {
        $this->rows[$sessionId]['status'] = SettlementStatus::FAILED;
    }

    public function linkOrder(string $sessionId, string $orderId): void
    {
        $this->rows[$sessionId]['orderId'] = $orderId;
    }

    private function isLocked(string $sessionId): bool
    {
        return \in_array($this->rows[$sessionId]['status'], [SettlementStatus::SETTLED, SettlementStatus::SETTLING], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(): array
    {
        return [
            'credential' => null,
            'status' => SettlementStatus::PENDING,
            'transactionHash' => null,
            'network' => null,
            'offeredEntry' => null,
            'quotedAmount' => null,
            'quotedCurrency' => null,
            'settledAmount' => null,
            'settledCurrency' => null,
            'orderId' => null,
        ];
    }
}

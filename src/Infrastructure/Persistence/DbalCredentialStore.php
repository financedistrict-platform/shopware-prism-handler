<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;

/**
 * DBAL-backed {@see CredentialStore}: the x402 credential + offered accepts + settlement status,
 * keyed by checkout session id (table `fd_prism_payment_settlement`).
 *
 * The settlement state machine ({@see \Fd\PrismPayment\Core\Settlement\SettlementStateMachine}) is
 * enforced here **atomically in SQL** rather than re-encoded in PHP: `capture()` is a conditional
 * upsert that can never revert a settled row (F0), and `claim()` is a single-row optimistic
 * transition pending -> settling (F1). `recordOffer()` and `capture()` write **disjoint** columns
 * so neither nulls the other.
 *
 * A dedicated table (rather than cart custom data) is used deliberately: the base SDK's cart
 * synchronization on `update` can replace cart contents, which would be an unreliable place to
 * stash a single-use payment authorization that must survive into `complete`.
 *
 * @internal
 */
final class DbalCredentialStore implements CredentialStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function recordOffer(string $sessionId, string $quotedAmount, string $quotedCurrency, array $offeredEntry): void
    {
        // Offer-first: create the row if absent, otherwise overwrite ONLY the offer column (a
        // wrapper {quotedAmount, quotedCurrency, entry}). Never touches credential/status, so it is
        // disjoint from capture()/invalidateCredential(); never disturbs a settled row (F0).
        $offer = $this->encode([
            'quotedAmount' => $quotedAmount,
            'quotedCurrency' => $quotedCurrency,
            'entry' => $offeredEntry,
        ]);

        $this->connection->executeStatement(
            'INSERT INTO fd_prism_payment_settlement
                (checkout_session_id, offered_accepts, status, created_at)
             VALUES (:id, :offer, :pending, :now)
             ON DUPLICATE KEY UPDATE
                offered_accepts = IF(status = :settled, offered_accepts, :offer),
                updated_at      = IF(status = :settled, updated_at, :now)',
            [
                'id' => $sessionId,
                'offer' => $offer,
                'pending' => SettlementStatus::PENDING,
                'settled' => SettlementStatus::SETTLED,
                'now' => $this->now(),
            ],
        );
    }

    public function invalidateCredential(string $sessionId): void
    {
        // Cart amount changed after signing (or settle failed): drop the credential and move to
        // failed so complete refuses until a fresh signature is submitted. Settled rows untouched.
        $this->connection->executeStatement(
            'UPDATE fd_prism_payment_settlement
             SET status = :failed, payment_payload = NULL, payment_requirements = NULL, updated_at = :now
             WHERE checkout_session_id = :id AND status <> :settled',
            [
                'id' => $sessionId,
                'failed' => SettlementStatus::FAILED,
                'settled' => SettlementStatus::SETTLED,
                'now' => $this->now(),
            ],
        );
    }

    public function releaseToBase(string $sessionId): void
    {
        // Agent chose another payment method: drop any Prism credential and return to pending so
        // complete defers to the base flow. Settled rows untouched.
        $this->connection->executeStatement(
            'UPDATE fd_prism_payment_settlement
             SET status = :pending, payment_payload = NULL, payment_requirements = NULL, updated_at = :now
             WHERE checkout_session_id = :id AND status <> :settled',
            [
                'id' => $sessionId,
                'pending' => SettlementStatus::PENDING,
                'settled' => SettlementStatus::SETTLED,
                'now' => $this->now(),
            ],
        );
    }

    /**
     * Capture (or replace) the pending credential. The conditional upsert leaves a `settled` row
     * completely untouched (F0) AND a `settling` row untouched (a re-capture mid-settle would reset
     * it to `pending` and let a second `complete` re-claim → double settle). Only `pending`/`failed`
     * rows are overwritten. Each assignment is guarded on the row's ORIGINAL status, and `status` is
     * assigned last so the earlier guards see the pre-update value.
     *
     * @param array<string, mixed> $paymentPayload
     * @param array<string, mixed> $paymentRequirements
     */
    public function capture(string $sessionId, array $paymentPayload, array $paymentRequirements): void
    {
        $this->connection->executeStatement(
            'INSERT INTO fd_prism_payment_settlement
                (checkout_session_id, payment_payload, payment_requirements, status, transaction_hash, network, created_at)
             VALUES (:id, :pp, :pr, :pending, NULL, NULL, :now)
             ON DUPLICATE KEY UPDATE
                payment_payload      = IF(status IN (:settled, :settling), payment_payload, :pp),
                payment_requirements = IF(status IN (:settled, :settling), payment_requirements, :pr),
                transaction_hash     = IF(status IN (:settled, :settling), transaction_hash, NULL),
                network              = IF(status IN (:settled, :settling), network, NULL),
                updated_at           = IF(status IN (:settled, :settling), updated_at, :now),
                status               = IF(status IN (:settled, :settling), status, :pending)',
            [
                'id' => $sessionId,
                'pp' => $this->encode($paymentPayload),
                'pr' => $this->encode($paymentRequirements),
                'pending' => SettlementStatus::PENDING,
                'settled' => SettlementStatus::SETTLED,
                'settling' => SettlementStatus::SETTLING,
                'now' => $this->now(),
            ],
        );
    }

    public function claim(string $sessionId): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE fd_prism_payment_settlement
             SET status = :settling, updated_at = :now
             WHERE checkout_session_id = :id AND status = :pending',
            [
                'id' => $sessionId,
                'settling' => SettlementStatus::SETTLING,
                'pending' => SettlementStatus::PENDING,
                'now' => $this->now(),
            ],
        );

        return 1 === (int) $affected;
    }

    public function load(string $sessionId): ?PrismSettlementRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT payment_payload, payment_requirements, status, transaction_hash, network, offered_accepts
             FROM fd_prism_payment_settlement WHERE checkout_session_id = :id',
            ['id' => $sessionId],
        );

        if (false === $row) {
            return null;
        }

        $offeredEntry = null;
        $quotedAmount = null;
        $quotedCurrency = null;
        if (null !== $row['offered_accepts']) {
            $offer = $this->decode((string) $row['offered_accepts']);
            $entry = $offer['entry'] ?? null;
            if (\is_array($entry)) {
                $offeredEntry = $entry;
                $quotedAmount = isset($offer['quotedAmount']) ? (string) $offer['quotedAmount'] : null;
                $quotedCurrency = isset($offer['quotedCurrency']) ? (string) $offer['quotedCurrency'] : null;
            }
        }

        return new PrismSettlementRecord(
            checkoutSessionId: $sessionId,
            paymentPayload: null !== $row['payment_payload'] ? $this->decode((string) $row['payment_payload']) : null,
            paymentRequirements: null !== $row['payment_requirements'] ? $this->decode((string) $row['payment_requirements']) : null,
            status: (string) $row['status'],
            transactionHash: null !== $row['transaction_hash'] ? (string) $row['transaction_hash'] : null,
            network: null !== $row['network'] ? (string) $row['network'] : null,
            offeredEntry: $offeredEntry,
            quotedAmount: $quotedAmount,
            quotedCurrency: $quotedCurrency,
        );
    }

    public function markSettled(string $sessionId, string $transactionHash, string $network): void
    {
        // Runs only after a won claim() (status = settling), completing settling -> settled. The
        // `status = :settling` guard makes that explicit in SQL: the row can only reach `settled`
        // from `settling`, so nothing can be forced terminal out of another state.
        $this->connection->executeStatement(
            'UPDATE fd_prism_payment_settlement
             SET status = :status, transaction_hash = :tx, network = :net, updated_at = :now
             WHERE checkout_session_id = :id AND status = :settling',
            [
                'id' => $sessionId,
                'status' => SettlementStatus::SETTLED,
                'settling' => SettlementStatus::SETTLING,
                'tx' => $transactionHash,
                'net' => $network,
                'now' => $this->now(),
            ],
        );
    }

    public function markFailed(string $sessionId): void
    {
        $this->connection->executeStatement(
            'UPDATE fd_prism_payment_settlement SET status = :status, updated_at = :now WHERE checkout_session_id = :id',
            ['id' => $sessionId, 'status' => SettlementStatus::FAILED, 'now' => $this->now()],
        );
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $json): array
    {
        $value = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($value)) {
            throw new \RuntimeException('Stored Prism credential is not a JSON object.');
        }

        return $value;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
    }
}

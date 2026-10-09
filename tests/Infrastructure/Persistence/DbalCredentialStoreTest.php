<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Infrastructure\Persistence\DbalCredentialStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbalCredentialStoreTest extends TestCase
{
    private const SESSION_ID = 'session-1';

    private const CREDENTIAL = '{"type":"x402"}';

    private \PDO $pdo;

    private DbalCredentialStore $store;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE fd_prism_payment_settlement (
                checkout_session_id TEXT PRIMARY KEY,
                credential TEXT NULL,
                status TEXT NOT NULL,
                transaction_hash TEXT NULL,
                network TEXT NULL,
                offered_accepts TEXT NULL,
                order_id BLOB NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NULL
            )',
        );
        $this->store = new DbalCredentialStore(new Connection($this->pdo));
    }

    #[DataProvider('lockedStatuses')]
    public function testReleaseToBaseLeavesLockedRowUntouched(string $status): void
    {
        $this->seed($status);

        $this->store->releaseToBase(self::SESSION_ID);

        self::assertSame(
            ['status' => $status, 'credential' => self::CREDENTIAL],
            $this->row(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lockedStatuses(): iterable
    {
        yield 'settled' => [SettlementStatus::SETTLED];
        yield 'settling' => [SettlementStatus::SETTLING];
    }

    #[DataProvider('releasableStatuses')]
    public function testReleaseToBaseResetsReleasableRowToPending(string $status): void
    {
        $this->seed($status);

        $this->store->releaseToBase(self::SESSION_ID);

        self::assertSame(
            ['status' => SettlementStatus::PENDING, 'credential' => null],
            $this->row(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function releasableStatuses(): iterable
    {
        yield 'pending' => [SettlementStatus::PENDING];
        yield 'failed' => [SettlementStatus::FAILED];
    }

    public function testReleaseToBaseDuringSettleKeepsSettlementCompletable(): void
    {
        $this->seed(SettlementStatus::PENDING);
        self::assertTrue($this->store->claim(self::SESSION_ID));

        $this->store->releaseToBase(self::SESSION_ID);
        $this->store->markSettled(self::SESSION_ID, '0xabc', 'base');

        self::assertFalse($this->store->claim(self::SESSION_ID));
        self::assertSame(
            ['status' => SettlementStatus::SETTLED, 'credential' => self::CREDENTIAL],
            $this->row(),
        );
    }

    /**
     * A cart change drops the quote AND the signature over it: `complete` then has no offer to match
     * against and fails closed, however the re-quote goes.
     */
    public function testInvalidateOfferClearsQuoteAndCredentialAndFailsTheRow(): void
    {
        $this->seed(SettlementStatus::PENDING, offer: '{"quotedAmount":"0.10"}');

        $this->store->invalidateOffer(self::SESSION_ID);

        self::assertSame(
            ['status' => SettlementStatus::FAILED, 'credential' => null, 'offered_accepts' => null],
            $this->fullRow(),
        );
    }

    /** An offer-only row has no signature to invalidate, so it loses the quote but keeps its status. */
    public function testInvalidateOfferOnOfferOnlyRowDropsTheQuoteButNotTheStatus(): void
    {
        $this->seed(SettlementStatus::PENDING, credential: null, offer: '{"quotedAmount":"0.10"}');

        $this->store->invalidateOffer(self::SESSION_ID);

        self::assertSame(
            ['status' => SettlementStatus::PENDING, 'credential' => null, 'offered_accepts' => null],
            $this->fullRow(),
        );
    }

    /**
     * A settled row is terminal, and a settling row is mid-flight on-chain: neither may be disturbed
     * by a cart change, or a payment that is happening gets marked failed underneath it.
     */
    #[DataProvider('lockedStatuses')]
    public function testInvalidateOfferLeavesLockedRowUntouched(string $status): void
    {
        $this->seed($status, offer: '{"quotedAmount":"0.10"}');

        $this->store->invalidateOffer(self::SESSION_ID);

        self::assertSame(
            ['status' => $status, 'credential' => self::CREDENTIAL, 'offered_accepts' => '{"quotedAmount":"0.10"}'],
            $this->fullRow(),
        );
    }

    /**
     * A settled payment is on-chain and terminal. `markFailed` carries no state check of its own at
     * the call site, so the guard has to live here — otherwise one caller that forgets is enough to
     * revert a paid row and let a second authorization be captured against it.
     */
    public function testMarkFailedLeavesASettledRowUntouched(): void
    {
        $this->seed(SettlementStatus::SETTLED);

        $this->store->markFailed(self::SESSION_ID);

        self::assertSame(
            ['status' => SettlementStatus::SETTLED, 'credential' => self::CREDENTIAL],
            $this->row(),
        );
    }

    /** The two legal predecessors of `failed`: rejected before the claim, and rejected on-chain. */
    #[DataProvider('failableStatuses')]
    public function testMarkFailedFailsARowThatHasNotSettled(string $status): void
    {
        $this->seed($status);

        $this->store->markFailed(self::SESSION_ID);

        self::assertSame(
            ['status' => SettlementStatus::FAILED, 'credential' => self::CREDENTIAL],
            $this->row(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failableStatuses(): iterable
    {
        yield 'pending' => [SettlementStatus::PENDING];
        yield 'settling' => [SettlementStatus::SETTLING];
    }

    /**
     * @return array<string, mixed>
     */
    private function fullRow(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, credential, offered_accepts FROM fd_prism_payment_settlement WHERE checkout_session_id = :id',
        );
        $statement->execute(['id' => self::SESSION_ID]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    private function seed(string $status, ?string $credential = self::CREDENTIAL, ?string $offer = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO fd_prism_payment_settlement (checkout_session_id, credential, status, offered_accepts, created_at)
             VALUES (:id, :credential, :status, :offer, :now)',
        )->execute([
            'id' => self::SESSION_ID,
            'credential' => $credential,
            'status' => $status,
            'offer' => $offer,
            'now' => '2026-09-30 00:00:00.000',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, credential FROM fd_prism_payment_settlement WHERE checkout_session_id = :id',
        );
        $statement->execute(['id' => self::SESSION_ID]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }
}

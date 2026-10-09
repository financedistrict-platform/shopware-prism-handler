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
                settled_quote_amount TEXT NULL,
                settled_quote_currency TEXT NULL,
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
        $this->store->markSettled(self::SESSION_ID, '0xabc', 'base', '10.00', 'EUR');

        self::assertFalse($this->store->claim(self::SESSION_ID));
        self::assertSame(
            ['status' => SettlementStatus::SETTLED, 'credential' => self::CREDENTIAL],
            $this->row(),
        );
    }

    public function testMarkSettledKeepsTheSettledAmountForTheOrderCheck(): void
    {
        $this->seed(SettlementStatus::PENDING);
        self::assertTrue($this->store->claim(self::SESSION_ID));

        $this->store->markSettled(self::SESSION_ID, '0xabc', 'base', '10.00', 'EUR');

        $record = $this->store->load(self::SESSION_ID);
        self::assertNotNull($record);
        self::assertSame('10.00', $record->settledQuoteAmount);
        self::assertSame('EUR', $record->settledQuoteCurrency);
    }

    #[DataProvider('lockedStatuses')]
    public function testInvalidateCredentialLeavesLockedRowUntouched(string $status): void
    {
        $this->seed($status);

        $this->store->invalidateCredential(self::SESSION_ID);

        self::assertSame(
            ['status' => $status, 'credential' => self::CREDENTIAL],
            $this->row(),
        );
    }

    public function testInvalidateCredentialDuringSettleKeepsSettlementRecordable(): void
    {
        $this->seed(SettlementStatus::PENDING);
        self::assertTrue($this->store->claim(self::SESSION_ID));

        $this->store->invalidateCredential(self::SESSION_ID);
        $this->store->markSettled(self::SESSION_ID, '0xabc', 'base', '10.00', 'EUR');

        $record = $this->store->load(self::SESSION_ID);
        self::assertNotNull($record);
        self::assertTrue($record->isSettled());
    }

    #[DataProvider('releasableStatuses')]
    public function testWithdrawOfferDropsTheQuoteOfAnOpenSession(string $status): void
    {
        $this->seedWithOffer($status);

        $this->store->withdrawOffer(self::SESSION_ID);

        $record = $this->store->load(self::SESSION_ID);
        self::assertNotNull($record);
        self::assertNull($record->offeredAccepts());
        self::assertNull($record->quotedAmount);
        self::assertFalse($record->quotedFor('10.00', 'EUR'));
    }

    #[DataProvider('lockedStatuses')]
    public function testWithdrawOfferKeepsTheQuoteOfALockedSession(string $status): void
    {
        $this->seedWithOffer($status);

        $this->store->withdrawOffer(self::SESSION_ID);

        $record = $this->store->load(self::SESSION_ID);
        self::assertNotNull($record);
        self::assertSame('10.00', $record->quotedAmount);
    }

    public function testLoadReadsTheQuoteTimeOfTheOffer(): void
    {
        $this->seed(SettlementStatus::PENDING);
        $this->pdo->prepare('UPDATE fd_prism_payment_settlement SET offered_accepts = :offer')->execute([
            'offer' => '{"quotedAmount":"10.00","quotedCurrency":"EUR","quotedAt":"2026-10-09T10:00:00+00:00","entry":{"config":{"accepts":[]}}}',
        ]);

        $record = $this->store->load(self::SESSION_ID);
        self::assertNotNull($record);
        self::assertEquals(new \DateTimeImmutable('2026-10-09T10:00:00+00:00'), $record->quotedAt);
    }

    public function testOfferWithoutReadableQuoteTimeLoadsWithoutOne(): void
    {
        $this->seedWithOffer(SettlementStatus::PENDING);

        $record = $this->store->load(self::SESSION_ID);
        self::assertNotNull($record);
        self::assertNull($record->quotedAt);
        self::assertFalse($record->quoteFreshAt(new \DateTimeImmutable()));
    }

    private function seedWithOffer(string $status): void
    {
        $this->seed($status);
        $this->pdo->prepare('UPDATE fd_prism_payment_settlement SET offered_accepts = :offer')->execute([
            'offer' => '{"quotedAmount":"10.00","quotedCurrency":"EUR","entry":{"config":{"accepts":[{"payTo":"0xmerchant"}]}}}',
        ]);
    }

    private function seed(string $status): void
    {
        $this->pdo->prepare(
            'INSERT INTO fd_prism_payment_settlement (checkout_session_id, credential, status, created_at)
             VALUES (:id, :credential, :status, :now)',
        )->execute([
            'id' => self::SESSION_ID,
            'credential' => self::CREDENTIAL,
            'status' => $status,
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

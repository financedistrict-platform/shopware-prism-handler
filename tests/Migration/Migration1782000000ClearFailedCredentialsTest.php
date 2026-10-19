<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Migration;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Migration\Migration1782000000ClearFailedCredentials;
use PHPUnit\Framework\TestCase;

final class Migration1782000000ClearFailedCredentialsTest extends TestCase
{
    private const CREDENTIAL = '{"type":"x402"}';

    /** Only failed rows lose the credential; a held or settled row still needs it. */
    public function testClearsTheCredentialOnFailedRowsOnly(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE fd_prism_payment_settlement (checkout_session_id TEXT PRIMARY KEY, credential TEXT NULL, status TEXT NOT NULL)');

        $statuses = [
            SettlementStatus::PENDING,
            SettlementStatus::SETTLING,
            SettlementStatus::SETTLED,
            SettlementStatus::FAILED,
        ];
        $insert = $pdo->prepare('INSERT INTO fd_prism_payment_settlement VALUES (:id, :credential, :status)');
        foreach ($statuses as $status) {
            $insert->execute(['id' => $status, 'credential' => self::CREDENTIAL, 'status' => $status]);
        }

        (new Migration1782000000ClearFailedCredentials())->update(new Connection($pdo));

        self::assertSame(
            [
                SettlementStatus::FAILED => null,
                SettlementStatus::PENDING => self::CREDENTIAL,
                SettlementStatus::SETTLED => self::CREDENTIAL,
                SettlementStatus::SETTLING => self::CREDENTIAL,
            ],
            $pdo->query('SELECT status, credential FROM fd_prism_payment_settlement ORDER BY status')->fetchAll(\PDO::FETCH_KEY_PAIR),
        );
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

final class ShopwareOrderTables
{
    private const OPEN_STATE = 'aa';

    public readonly \PDO $pdo;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE currency (id BLOB PRIMARY KEY, iso_code TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE `order` (id BLOB NOT NULL, version_id BLOB NOT NULL, currency_id BLOB NOT NULL, amount_total REAL NOT NULL)');
        $this->pdo->exec('CREATE TABLE state_machine_state (id BLOB PRIMARY KEY, technical_name TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE order_transaction (id BLOB PRIMARY KEY, order_id BLOB NOT NULL, state_id BLOB NOT NULL, created_at TEXT NOT NULL)');
        $this->pdo->prepare('INSERT INTO state_machine_state VALUES (unhex(:id), :name)')
            ->execute(['id' => self::OPEN_STATE, 'name' => 'open']);
    }

    public function connection(): Connection
    {
        return new Connection($this->pdo);
    }

    public function orderCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM `order`')->fetchColumn();
    }

    public function placeOrder(string $orderId, string $total, string $currency): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO currency VALUES (unhex(:id), :iso)')
            ->execute(['id' => bin2hex($currency), 'iso' => $currency]);
        $this->pdo->prepare('INSERT INTO `order` VALUES (unhex(:id), unhex(:version), unhex(:currency), :total)')
            ->execute([
                'id' => $orderId,
                'version' => Defaults::LIVE_VERSION,
                'currency' => bin2hex($currency),
                'total' => (float) $total,
            ]);
        $this->pdo->prepare('INSERT INTO order_transaction VALUES (unhex(:id), unhex(:order), unhex(:state), :createdAt)')
            ->execute([
                'id' => strrev($orderId),
                'order' => $orderId,
                'state' => self::OPEN_STATE,
                'createdAt' => '2026-10-09 00:00:00',
            ]);
    }
}

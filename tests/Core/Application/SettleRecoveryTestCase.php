<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Payment\SettleResult;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use Fd\PrismPayment\Infrastructure\Persistence\DbalCredentialStore;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelResolution;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;

/**
 * The adapter over the real settlement store (SQLite) and a scripted Prism, starting from a
 * checkout whose credential is captured and pending. For the paths where what the row holds after
 * each answer is the point.
 */
abstract class SettleRecoveryTestCase extends CheckoutAdapterTestCase
{
    protected \PDO $pdo;
    protected object $prism;
    protected int $paidCalls = 0;
    protected int $failCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('UNHEX', static fn (string $hex): string => (string) hex2bin($hex), 1);
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
        $this->pdo->prepare(
            'INSERT INTO fd_prism_payment_settlement (checkout_session_id, credential, status, offered_accepts, created_at)
             VALUES (:id, :credential, :status, :offer, :now)',
        )->execute([
            'id' => self::SESSION,
            'credential' => json_encode($this->credential()),
            'status' => SettlementStatus::PENDING,
            'offer' => json_encode(['quotedAmount' => self::QUOTE, 'quotedCurrency' => 'USD', 'entry' => self::OFFER]),
            'now' => '2026-09-30 00:00:00.000',
        ]);

        $this->prism = new class implements PrismGateway {
            /** @var list<SettleResult|\Throwable> */
            public array $script = [];

            /** @var list<array<string, mixed>> */
            public array $settled = [];

            public function paymentRequirements(
                PrismConfig $config,
                string $amount,
                string $currency,
                string $resourceUrl,
                ?string $resourceDescription,
            ): array {
                return [];
            }

            public function settle(PrismConfig $config, array $credential): SettleResult
            {
                $this->settled[] = $credential;
                $next = array_shift($this->script);
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        };

        $this->inner->method('completeCheckout')->willReturn($this->completed(12.50));
        $this->transactions->method('paid')->willReturnCallback(function (): void {
            ++$this->paidCalls;
            $this->transactionState = 'paid';
        });
        $this->transactions->method('fail')->willReturnCallback(function (): void {
            ++$this->failCalls;
            $this->transactionState = 'failed';
        });
    }

    protected function adapter(): PrismCheckoutAdapter
    {
        $config = $this->createMock(ConfigResolver::class);
        $config->method('resolve')->willReturn(new PrismConfig('https://prism.test', 'key'));

        $domains = $this->createMock(SalesChannelDomainResolver::class);
        $domains->method('resolveByBaseUri')->willReturn(new SalesChannelResolution('sc-1'));

        return new PrismCheckoutAdapter(
            $this->inner,
            new DbalCredentialStore(new Connection($this->pdo)),
            $this->prism,
            $config,
            new RequestSalesChannelResolver($domains),
            $this->transactions,
            $this->transactionRepository,
            $this->connection,
            new SettlementStateMachine(),
            new AcceptsMatcher(),
        );
    }

    /** @return array{string, ?string, ?string} status, transaction hash, credential */
    protected function row(): array
    {
        return $this->pdo->query(
            "SELECT status, transaction_hash, credential FROM fd_prism_payment_settlement WHERE checkout_session_id = '" . self::SESSION . "'",
        )->fetch(\PDO::FETCH_NUM);
    }

    protected function rowStatus(): string
    {
        return $this->row()[0];
    }

    /** Ages the claim past the settle window, so a retry may take the settle over. */
    protected function expireClaim(): void
    {
        $this->pdo->exec("UPDATE fd_prism_payment_settlement SET updated_at = '2026-09-30 00:00:00.000'");
    }

    /** @param array<string, mixed> $credential */
    protected function instrument(array $credential): PaymentInstrument
    {
        return new PaymentInstrument(type: 'x402', handlerId: HandlerId::PRISM, credential: $credential);
    }

    /** @return array<string, mixed> */
    protected function otherCredential(): array
    {
        $credential = $this->credential();
        $credential['paymentPayload']['payload']['signature'] = '0xother';

        return $credential;
    }

    protected function alreadySettled(string $transaction): SettleResult
    {
        return new SettleResult(false, $transaction, 'eip155:84532', null, 'checkout_already_settled');
    }
}

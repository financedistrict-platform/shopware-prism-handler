<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Core\Port\SettlementReadModel;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Core\Settlement\SettlementView;
use Shopware\Core\Defaults;

/**
 * DBAL-backed {@see SettlementReadModel}: projects the display facts out of the settlement row's
 * JSON columns.
 *
 * @internal
 */
final class DbalSettlementReadModel implements SettlementReadModel
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function findByOrderId(string $orderId): ?SettlementView
    {
        $row = $this->connection->fetchAssociative(
            'SELECT s.credential, s.offered_accepts, s.transaction_hash, s.network, s.updated_at,
                    LOWER(HEX(o.sales_channel_id)) AS sales_channel_id
             FROM fd_prism_payment_settlement s
             LEFT JOIN `order` o ON o.id = s.order_id AND o.version_id = UNHEX(:liveVersion)
             WHERE s.order_id = UNHEX(:orderId) AND s.status = :settled
             LIMIT 1',
            [
                'orderId' => $orderId,
                'settled' => SettlementStatus::SETTLED,
                'liveVersion' => Defaults::LIVE_VERSION,
            ],
        );

        if (false === $row) {
            return null;
        }

        // The credential is the wallet's whole x402 object; read the display facts out of it
        // (paymentRequirements = asset/amount, paymentPayload = payer). Read-only projection.
        $credential = $this->decode($row['credential']);
        $requirements = \is_array($credential['paymentRequirements'] ?? null) ? $credential['paymentRequirements'] : [];
        $payload = \is_array($credential['paymentPayload'] ?? null) ? $credential['paymentPayload'] : [];
        $offer = $this->decode($row['offered_accepts']);

        return new SettlementView(
            network: (string) $row['network'],
            asset: $requirements['asset'] ?? null,
            amountAtomic: isset($requirements['amount']) ? (string) $requirements['amount'] : null,
            transactionHash: null !== $row['transaction_hash'] ? (string) $row['transaction_hash'] : null,
            settledAt: $this->toIso8601($row['updated_at']),
            fiatAmount: isset($offer['quotedAmount']) ? (string) $offer['quotedAmount'] : null,
            fiatCurrency: isset($offer['quotedCurrency']) ? (string) $offer['quotedCurrency'] : null,
            payer: $payload['payload']['authorization']['from'] ?? null,
            salesChannelId: null !== $row['sales_channel_id'] ? (string) $row['sales_channel_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (!\is_string($json) || '' === $json) {
            return [];
        }

        $value = json_decode($json, true);

        return \is_array($value) ? $value : [];
    }

    private function toIso8601(?string $dbDateTime): ?string
    {
        if (null === $dbDateTime || '' === $dbDateTime) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($dbDateTime))->format(\DATE_ATOM);
        } catch (\Exception) {
            return $dbDateTime;
        }
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Payment;

use Fd\PrismPayment\Core\Exception\PrismApiException;

/**
 * @internal
 */
final class PrismResponseParser
{
    private const PAYMENT_REQUIREMENTS_PATH = '/api/v2/merchant/payment-requirements';

    private const SETTLE_PATH = '/api/v2/payment/settle';

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function paymentRequirementsConfig(array $data): array
    {
        if (!\array_key_exists('x402Version', $data) || !isset($data['accepts']) || !\is_array($data['accepts'])) {
            throw new PrismApiException(sprintf(
                'Prism %s returned a payment-requirements body without x402Version/accepts: %s',
                self::PAYMENT_REQUIREMENTS_PATH,
                json_encode($data),
            ));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function settleResult(array $data): SettleResult
    {
        foreach (['success', 'transaction', 'network'] as $key) {
            if (!\array_key_exists($key, $data)) {
                throw new PrismApiException(sprintf(
                    'Prism %s response missing required field "%s": %s',
                    self::SETTLE_PATH,
                    $key,
                    json_encode($data),
                ));
            }
        }

        return new SettleResult(
            success: (bool) $data['success'],
            transaction: (string) $data['transaction'],
            network: (string) $data['network'],
            payer: isset($data['payer']) ? (string) $data['payer'] : null,
            errorReason: isset($data['errorReason']) ? (string) $data['errorReason'] : null,
        );
    }
}

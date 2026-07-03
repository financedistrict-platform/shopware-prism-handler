<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Payment;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Ucp\HandlerId;

/**
 * Pure parser/validator for Prism merchant-API responses. Operates on an already-decoded
 * array (the HTTP/transport/status/json-decode concerns stay in the Infrastructure client)
 * and throws {@see PrismApiException} on anything malformed. No I/O, no framework — unit-testable
 * in isolation.
 *
 * @internal
 */
final class PrismResponseParser
{
    private const PAYMENT_REQUIREMENTS_PATH = '/api/v2/merchant/ucp/payment-requirements';

    private const SETTLE_PATH = '/api/v2/payment/settle';

    /**
     * Validate a payment-requirements response and return the first handler entry
     * {id, version, config} verbatim, where config carries x402Version + accepts[].
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function paymentRequirementsEntry(array $data): array
    {
        $entry = $this->firstHandlerEntry($data, self::PAYMENT_REQUIREMENTS_PATH, ['id', 'version', 'config']);
        $configBlock = $entry['config'];

        if (!\is_array($configBlock)
            || !\array_key_exists('x402Version', $configBlock)
            || !isset($configBlock['accepts']) || !\is_array($configBlock['accepts'])
        ) {
            throw new PrismApiException(sprintf(
                'Prism %s returned a handler config without x402Version/accepts: %s',
                self::PAYMENT_REQUIREMENTS_PATH,
                json_encode($configBlock),
            ));
        }

        return $entry;
    }

    /**
     * Validate a settle response and return the parsed {@see SettleResult}.
     *
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

    /**
     * @param array<string, mixed> $data
     * @param list<string> $requiredKeys
     *
     * @return array<string, mixed>
     */
    private function firstHandlerEntry(array $data, string $path, array $requiredKeys): array
    {
        $key = HandlerId::PRISM;

        if (!isset($data[$key]) || !\is_array($data[$key]) || [] === $data[$key]) {
            throw new PrismApiException(sprintf('Prism %s response has no "%s" entries: %s', $path, $key, json_encode($data)));
        }

        $entry = $data[$key][0];
        if (!\is_array($entry)) {
            throw new PrismApiException(sprintf('Prism %s first "%s" entry is not an object.', $path, $key));
        }

        foreach ($requiredKeys as $required) {
            if (!\array_key_exists($required, $entry)) {
                throw new PrismApiException(sprintf('Prism %s handler entry missing "%s": %s', $path, $required, json_encode($entry)));
            }
        }

        return $entry;
    }
}

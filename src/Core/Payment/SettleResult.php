<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Payment;

/**
 * Parsed Prism `POST /api/v2/payment/settle` response (SettleResponse).
 * `transaction` is the on-chain tx hash; `network` is the CAIP-2 chain id.
 *
 * @internal
 */
final readonly class SettleResult
{
    private const REVIEW_REASONS = [
        'authorization_consumed',
        'settlement_unresolved',
    ];

    private const DECLINE_REASONS = [
        'authorization_mismatch',
        'unsupported_network',
        'unsupported_token',
        'point_of_service_not_found',
        'seller_wallet_not_configured',
        'sanctions_hit',
        'risk_threshold_exceeded',
        'affiliate_residual_overflow',
        'affiliate_settlement_unavailable',
        'cashback_outside_campaign_bounds',
        'invalid_payment_accepted_mismatch',
        'network_not_found',
        'network_not_configured',
        'solana_network_not_configured',
        'missing_transaction',
        'invalid_signature',
        'invalid_authorization_recipient',
        'invalid_authorization_format',
        'invalid_exact_evm_payload_recipient_mismatch',
        'invalid_exact_evm_payload_authorization_value',
        'invalid_exact_evm_payload_authorization_time_bounds',
        'invalid_exact_evm_payload_authorization_expired',
        'invalid_exact_evm_payload_authorization_not_yet_valid',
        'invalid_exact_evm_payload_authorization_missing_signature',
        'invalid_exact_evm_payload_authorization_signature',
        'unauthorized',
        'insufficient_funds',
        'balance_check_failed',
        'balance_verification_failed',
        'validation_error',
    ];

    private const DECLINE_REASON_PREFIXES = [
        'validation_failed:',
        'invalid_transaction:',
        'unknown_network:',
        'unknown_token:',
        'missing_variant_config:',
    ];

    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public ?string $payer,
        public ?string $errorReason,
    ) {
    }

    public function isDefiniteDecline(): bool
    {
        if ($this->success || null === $this->errorReason) {
            return false;
        }

        if (\in_array($this->errorReason, self::DECLINE_REASONS, true)) {
            return true;
        }

        foreach (self::DECLINE_REASON_PREFIXES as $prefix) {
            if (str_starts_with($this->errorReason, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function needsReview(): bool
    {
        return !$this->success && \in_array($this->errorReason, self::REVIEW_REASONS, true);
    }

    public function isPending(): bool
    {
        return !$this->success && !$this->isDefiniteDecline() && !$this->needsReview();
    }
}

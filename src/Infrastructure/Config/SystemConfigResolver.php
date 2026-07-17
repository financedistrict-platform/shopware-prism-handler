<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Config;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Shopware-backed {@see ConfigResolver}: resolves the Prism gateway URL + API key per
 * request/sales-channel, always from the plugin's admin config fields (system_config):
 *
 *  - URL = store-wide {@see GATEWAY_URL_CONFIG_KEY}, falling back to the prod default when unset.
 *  - key = per-sales-channel {@see API_KEY_CONFIG_KEY}; missing → throw.
 *
 * @internal
 */
final class SystemConfigResolver implements ConfigResolver
{
    /** Default gateway, used when the operator has not overridden {@see GATEWAY_URL_CONFIG_KEY}. */
    private const PROD_URL = 'https://prism-gw.fd.xyz';

    public const API_KEY_CONFIG_KEY = 'FdPrismPayment.config.prismApiKey';

    public const GATEWAY_URL_CONFIG_KEY = 'FdPrismPayment.config.prismGatewayUrl';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function resolve(?string $salesChannelId): PrismConfig
    {
        $baseUrl = $this->gatewayUrl();

        $key = $this->systemConfig->getString(self::API_KEY_CONFIG_KEY, $salesChannelId);
        if ('' === $key) {
            throw new PrismApiException(sprintf(
                'Prism API key is not configured (%s) for sales channel %s.',
                self::API_KEY_CONFIG_KEY,
                $salesChannelId ?? '<default>',
            ));
        }

        return new PrismConfig($baseUrl, $key);
    }

    public function gatewayUrl(): string
    {
        // Store-wide setting (unlike the per-channel key); fall back to the prod default when unset.
        $configured = trim($this->systemConfig->getString(self::GATEWAY_URL_CONFIG_KEY));

        return '' !== $configured ? rtrim($configured, '/') : self::PROD_URL;
    }
}

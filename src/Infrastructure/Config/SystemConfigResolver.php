<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Config;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Shopware-backed {@see ConfigResolver}: resolves the Prism gateway URL + API key per
 * request/sales-channel.
 *
 *  1. DEVELOPER_MODE truthy → use env PRISM_URL + PRISM_API_KEY (test pair). This is the
 *     ONLY switch that honors env; it is an instance-global dev convenience.
 *  2. otherwise → URL = fixed prod constant, key = system_config for the sales channel.
 *
 * DEVELOPER_MODE on but env missing → throw (never silently fall back to prod).
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

        if ($this->developerMode()) {
            $key = $this->env('PRISM_API_KEY');
            if (null === $key) {
                throw new PrismApiException(
                    'DEVELOPER_MODE is enabled but PRISM_API_KEY is not set. '
                    . 'Refusing to fall back to the production gateway.',
                );
            }

            return new PrismConfig($baseUrl, $key);
        }

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
        if ($this->developerMode()) {
            $url = $this->env('PRISM_URL');
            if (null === $url) {
                throw new PrismApiException('DEVELOPER_MODE is enabled but PRISM_URL is not set.');
            }

            return rtrim($url, '/');
        }

        // Store-wide setting (unlike the per-channel key); fall back to the prod default when unset.
        $configured = trim($this->systemConfig->getString(self::GATEWAY_URL_CONFIG_KEY));

        return '' !== $configured ? rtrim($configured, '/') : self::PROD_URL;
    }

    public function isDeveloperMode(): bool
    {
        return $this->developerMode();
    }

    private function developerMode(): bool
    {
        $value = $this->env('DEVELOPER_MODE');

        return null !== $value && \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function env(string $key): ?string
    {
        $value = getenv($key);
        if (false === $value || '' === $value) {
            return null;
        }

        return $value;
    }
}

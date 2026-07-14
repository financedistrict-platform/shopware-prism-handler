<?php

declare(strict_types=1);

namespace Fd\PrismPayment\DependencyInjection;

use Fd\PrismPayment\Application\Payment\PrismX402PaymentHandler;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Port\SettlementReadModel;
use Fd\PrismPayment\Infrastructure\Config\SystemConfigResolver;
use Fd\PrismPayment\Infrastructure\Http\PrismHttpClient;
use Fd\PrismPayment\Infrastructure\Persistence\DbalCredentialStore;
use Fd\PrismPayment\Infrastructure\Persistence\DbalSettlementReadModel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // autoconfigure() is REQUIRED: the UCP SDK bundle registers
    // registerForAutoconfiguration(PaymentHandlerInterface::class)->addTag('ucp_sdk.payment_handler')
    // at kernel compile time. Our services only receive that tag if autoconfigure is on.
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->load('Fd\\PrismPayment\\', __DIR__ . '/../../*')
        ->exclude([__DIR__ . '/../../Resources', __DIR__ . '/../../FdPrismPayment.php']);

    // Bind each Core port to its Infrastructure implementation so Application classes can
    // type-hint the abstraction (and be tested against in-memory fakes). The implementations
    // themselves are registered + autowired by the load() above.
    $services->alias(PrismGateway::class, PrismHttpClient::class);
    $services->alias(CredentialStore::class, DbalCredentialStore::class);
    $services->alias(SettlementReadModel::class, DbalSettlementReadModel::class);
    $services->alias(ConfigResolver::class, SystemConfigResolver::class);

    // The Shopware payment method handler is resolved by the core via this tag (service id
    // == handlerIdentifier). No marker interface exists for autoconfigure, so tag explicitly.
    $services->set(PrismX402PaymentHandler::class)
        ->tag('shopware.payment.method');

    // Decorate the base checkout adapter to capture the x402 credential on update and settle
    // on complete. The decorated (inner) service is the base ShopwareCheckoutAdapter, referenced
    // by its FQCN service id (it is not in our autoload, so we use the string id, not the class).
    $services->set(PrismCheckoutAdapter::class)
        ->decorate('Swag\\AgenticCommerce\\Ucp\\Adapter\\ShopwareCheckoutAdapter')
        ->autowire()
        ->arg('$inner', service('.inner'))
        ->arg('$transactionRepository', service('order_transaction.repository'));
};

<?php

declare(strict_types=1);

namespace Fd\PrismPayment;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Application\Payment\PrismX402PaymentHandler;
use Fd\PrismPayment\Infrastructure\OrderCustomFields;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

/**
 * Plugin bootstrap. The plugin registers a Prism/x402 UCP payment handler into the
 * surface provided by SwagAgenticCommerce (which loads the UCP SDK autoloader + bundle
 * into the kernel). We do NOT bundle our own SDK copy; the `Ucp\Sdk\…` classes resolve
 * at runtime from the sibling plugin's vendor/.
 *
 * It also registers a dedicated Shopware payment method ("Prism (x402 on-chain)") so
 * settled orders carry a correct method instead of the sales-channel default.
 *
 * The token `FdPrismPayment` is a renameable placeholder (namespace, class, composer
 * name, install arg). The externally-stable UCP handler id `xyz.fd.prism_payment` is
 * independent of the plugin name.
 */
final class FdPrismPayment extends Plugin
{
    /**
     * No third-party composer dependencies of our own — everything we use is provided by
     * the platform or the sibling SwagAgenticCommerce plugin. Skip composer execution so
     * `shopware/agentic-commerce` (a sibling plugin, not a packagist package) is not
     * resolved from packagist; Shopware enforces that dependency via plugin state instead.
     */
    public function executeComposerCommands(): bool
    {
        return false;
    }

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->upsertPaymentMethod($installContext->getContext());
        // Create the method INACTIVE on install (explicit, per Shopware's payment-plugin guide).
        // activate() flips it on. The shared upsertPaymentMethod() deliberately omits `active` so
        // update() preserves the operator's choice — so we set the install-time default here.
        $this->setPaymentMethodActive(false, $installContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        // Idempotent upsert so the method exists even if the plugin was installed before this code
        // was added; then mark the payment method active.
        $this->upsertPaymentMethod($activateContext->getContext());
        $this->setPaymentMethodActive(true, $activateContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        // update() runs on version upgrade (not install/activate); re-run the idempotent upsert.
        // Does not toggle active state — an update preserves the operator's choice.
        $this->upsertPaymentMethod($updateContext->getContext());
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->setPaymentMethodActive(false, $deactivateContext->getContext());
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Always deactivate — NEVER delete — the payment method: placed orders reference it via FK,
        // and Shopware's payment-plugin guide is explicit that uninstall must preserve order-data
        // integrity. The method row stays so historical orders keep a resolvable payment method.
        $this->setPaymentMethodActive(false, $uninstallContext->getContext());

        // The operator asked to keep user data — leave the settlement table and custom-field set.
        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Hard uninstall: the operator explicitly unticked "keep user data", so remove the schema we
        // created. The custom-field set delete cascades to its relation + fields; the settlement
        // table is our private DBAL table (no FK from orders), so dropping it is safe.
        /** @var EntityRepository $customFieldSets */
        $customFieldSets = $this->container->get('custom_field_set.repository');
        $customFieldSets->delete([['id' => OrderCustomFields::SET_ID]], $uninstallContext->getContext());

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `fd_prism_payment_settlement`');
    }

    private function upsertPaymentMethod(Context $context): void
    {
        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        // Intentionally NOT assigned to any sales channel's available payment methods: this method
        // is attached to a transaction only by PrismCheckoutAdapter::markOrderPaid() AFTER a
        // confirmed on-chain settle, so it must never be storefront-selectable. (As a hard guard,
        // PrismX402PaymentHandler::pay() declines — a mistaken channel assignment fails loudly
        // instead of placing an unpaid order.)
        $this->paymentMethodRepository()->upsert([[
            'id' => PrismX402PaymentHandler::PAYMENT_METHOD_ID,
            'handlerIdentifier' => PrismX402PaymentHandler::class,
            'technicalName' => PrismX402PaymentHandler::TECHNICAL_NAME,
            'pluginId' => $pluginId,
            'afterOrderEnabled' => false,
            'translations' => [
                'en-GB' => [
                    'name' => 'Prism (x402 on-chain)',
                    'description' => 'Agentic payment settled on-chain via the Prism x402 gateway.',
                ],
                'de-DE' => [
                    'name' => 'Prism (x402 On-Chain)',
                    'description' => 'Agentenzahlung, on-chain über das Prism-x402-Gateway abgewickelt.',
                ],
            ],
        ]], $context);
    }

    private function setPaymentMethodActive(bool $active, Context $context): void
    {
        $repository = $this->paymentMethodRepository();

        // Guard: only toggle if the method already exists — a bare {id, active} upsert on a
        // missing row would violate the required handlerIdentifier/technicalName fields.
        $exists = $repository->searchIds(
            new Criteria([PrismX402PaymentHandler::PAYMENT_METHOD_ID]),
            $context,
        )->getTotal() > 0;

        if (!$exists) {
            return;
        }

        $repository->upsert([[
            'id' => PrismX402PaymentHandler::PAYMENT_METHOD_ID,
            'active' => $active,
        ]], $context);
    }

    private function paymentMethodRepository(): EntityRepository
    {
        /** @var EntityRepository $repository */
        $repository = $this->container->get('payment_method.repository');

        return $repository;
    }
}

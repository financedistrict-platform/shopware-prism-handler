<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Admin;

use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\SettlementReadModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API read for the Prism merchant card: resolves the settlement via {@see SettlementReadModel}
 * and serializes it. Reads our own store only, never Prism.
 *
 * @internal
 */
#[Route(defaults: ['_routeScope' => ['api']])]
final class PrismSettlementController extends AbstractController
{
    public function __construct(
        private readonly SettlementReadModel $readModel,
        private readonly ConfigResolver $configResolver,
    ) {
    }

    #[Route(
        path: '/api/_action/fd-prism-payment/order/{orderId}/settlement',
        name: 'api.action.fd_prism_payment.order.settlement',
        requirements: ['orderId' => '[0-9a-fA-F]{32}'],
        defaults: ['_acl' => ['order:read']],
        methods: ['GET'],
    )]
    public function settlement(string $orderId): JsonResponse
    {
        $view = $this->readModel->findByOrderId($orderId);

        // Not a settled Prism order (no row, or paid by another method) → the card hides itself.
        if (null === $view) {
            return new JsonResponse(['settled' => false]);
        }

        return new JsonResponse([
            'settled' => true,
            'network' => $view->network,
            'asset' => $view->asset,
            'amountAtomic' => $view->amountAtomic,
            'txHash' => $view->transactionHash,
            'settledAt' => $view->settledAt,
            'fiatAmount' => $view->fiatAmount,
            'fiatCurrency' => $view->fiatCurrency,
            'payer' => $view->payer,
            // The card derives the "View details on Prism" link from this host (apps.<base>/prism/sales).
            'gatewayUrl' => $this->configResolver->gatewayUrl(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Port\HandlerDeclarationSource;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;

final class UnreachableDeclarationSource implements HandlerDeclarationSource
{
    public function fetch(PrismConfig $config, string $ucpVersion): HandlerDeclaration
    {
        throw new PrismApiException('Prism is unavailable.');
    }
}

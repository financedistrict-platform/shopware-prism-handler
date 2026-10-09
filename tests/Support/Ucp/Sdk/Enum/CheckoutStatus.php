<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum CheckoutStatus: string
{
    case Incomplete = 'incomplete';
    case ReadyForComplete = 'ready_for_complete';
    case Completed = 'completed';
    case Canceled = 'canceled';
}

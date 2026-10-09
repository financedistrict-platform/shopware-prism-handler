<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

/**
 * @internal
 */
final class PrismCheckoutEntry
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array{id: string, version: string, config: array<string, mixed>}
     */
    public static function compose(HandlerDeclaration $declaration, array $config): array
    {
        return ['id' => $declaration->id, 'version' => $declaration->version, 'config' => $config];
    }
}

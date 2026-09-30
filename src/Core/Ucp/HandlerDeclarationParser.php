<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

use Fd\PrismPayment\Core\Exception\PrismApiException;

final class HandlerDeclarationParser
{
    private const INSTRUMENT_TYPE = 'x402';

    public static function schemaUrl(array $handlersBody, string $gatewayBaseUrl): string
    {
        return self::entry($handlersBody, $gatewayBaseUrl)['schema'];
    }

    public static function parse(array $handlersBody, array $schemaDocument, string $gatewayBaseUrl): HandlerDeclaration
    {
        $entry = self::entry($handlersBody, $gatewayBaseUrl);

        $instrumentRef = $schemaDocument['$defs'][HandlerId::PRISM]['instrument']['$ref'] ?? null;
        if (!\is_string($instrumentRef) || '' === $instrumentRef) {
            throw new PrismApiException(sprintf(
                'Prism schema.json has no $defs["%s"].instrument.$ref',
                HandlerId::PRISM,
            ));
        }
        self::assertSameOrigin($instrumentRef, $gatewayBaseUrl, 'instrument $ref');

        return new HandlerDeclaration(
            $entry['id'],
            $entry['version'],
            $entry['spec'],
            $entry['schema'],
            $instrumentRef,
        );
    }

    private static function entry(array $handlersBody, string $gatewayBaseUrl): array
    {
        $entry = $handlersBody[HandlerId::PRISM][0] ?? null;
        if (!\is_array($entry)) {
            throw new PrismApiException(sprintf('Prism handlers response has no "%s" entry', HandlerId::PRISM));
        }

        $fields = [];
        foreach (['id', 'version', 'spec', 'schema'] as $field) {
            $value = $entry[$field] ?? null;
            if (!\is_string($value) || '' === $value) {
                throw new PrismApiException(sprintf('Prism handler entry field "%s" is missing or empty', $field));
            }
            $fields[$field] = $value;
        }

        if (HandlerId::PRISM !== $fields['id']) {
            throw new PrismApiException(sprintf(
                'Prism handler entry "id" must be "%s", got "%s"',
                HandlerId::PRISM,
                $fields['id'],
            ));
        }

        $instruments = $entry['available_instruments'] ?? null;
        $hasX402 = \is_array($instruments) && [] !== array_filter(
            $instruments,
            static fn (mixed $i): bool => \is_array($i) && self::INSTRUMENT_TYPE === ($i['type'] ?? null),
        );
        if (!$hasX402) {
            throw new PrismApiException(sprintf(
                'Prism handler entry "available_instruments" has no "%s" instrument',
                self::INSTRUMENT_TYPE,
            ));
        }

        self::assertSameOrigin($fields['spec'], $gatewayBaseUrl, 'spec');
        self::assertSameOrigin($fields['schema'], $gatewayBaseUrl, 'schema');

        return $fields;
    }

    private static function assertSameOrigin(string $url, string $gatewayBaseUrl, string $field): void
    {
        $target = parse_url($url);
        $gateway = parse_url($gatewayBaseUrl);

        $sameOrigin = \is_array($target) && \is_array($gateway)
            && 'https' === strtolower($target['scheme'] ?? '')
            && 'https' === strtolower($gateway['scheme'] ?? '')
            && '' !== ($target['host'] ?? '')
            && strtolower($target['host'] ?? '') === strtolower($gateway['host'] ?? '')
            && ($target['port'] ?? 443) === ($gateway['port'] ?? 443)
            && !isset($target['user'])
            && !isset($target['pass']);

        if (!$sameOrigin) {
            throw new PrismApiException(sprintf(
                'Prism handler %s URL "%s" is not an https URL on the gateway host',
                $field,
                $url,
            ));
        }
    }
}

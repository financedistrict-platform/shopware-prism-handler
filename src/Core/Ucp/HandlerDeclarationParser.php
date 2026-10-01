<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Ucp;

use Fd\PrismPayment\Core\Exception\PrismApiException;

final class HandlerDeclarationParser
{
    private const INSTRUMENT_TYPE = InstrumentAcceptance::INSTRUMENT_TYPE;

    public static function schemaUrl(array $handlersBody, string $gatewayBaseUrl): string
    {
        return self::entry($handlersBody, $gatewayBaseUrl)['schema'];
    }

    public static function declaredInstrumentSchema(array $handlersBody, string $gatewayBaseUrl): ?string
    {
        return self::entry($handlersBody, $gatewayBaseUrl)['instrumentSchema'];
    }

    public static function parse(array $handlersBody, ?array $schemaDocument, string $gatewayBaseUrl): HandlerDeclaration
    {
        $entry = self::entry($handlersBody, $gatewayBaseUrl);

        return new HandlerDeclaration(
            HandlerId::PRISM,
            $entry['version'],
            $entry['spec'],
            $entry['schema'],
            $entry['instrumentSchema'] ?? self::linkedInstrumentSchema($schemaDocument, $gatewayBaseUrl),
        );
    }

    private static function linkedInstrumentSchema(?array $schemaDocument, string $gatewayBaseUrl): string
    {
        $instrumentRef = $schemaDocument['$defs'][HandlerId::PRISM]['instrument']['$ref'] ?? null;
        if (!\is_string($instrumentRef) || '' === $instrumentRef) {
            throw new PrismApiException(sprintf(
                'Prism schema.json has no $defs["%s"].instrument.$ref',
                HandlerId::PRISM,
            ));
        }
        self::assertSameOrigin($instrumentRef, $gatewayBaseUrl, 'instrument $ref');

        return $instrumentRef;
    }

    /**
     * @return array{id: string, version: string, spec: string, schema: string, instrumentSchema: ?string}
     */
    private static function entry(array $handlersBody, string $gatewayBaseUrl): array
    {
        $entry = $handlersBody[HandlerId::PRISM][0] ?? null;
        if (!\is_array($entry)) {
            throw new PrismApiException(sprintf('Prism handlers response has no "%s" entry', HandlerId::PRISM));
        }

        $fields = [];
        foreach (['id', 'version', 'spec'] as $field) {
            $fields[$field] = self::requiredString($entry[$field] ?? null, $field);
        }
        $fields['schema'] = self::requiredString($entry['schema'] ?? $entry['config_schema'] ?? null, 'schema');

        if (!\in_array($fields['id'], HandlerId::ALL, true)) {
            throw new PrismApiException(sprintf(
                'Prism handler entry "id" must be one of "%s", got "%s"',
                implode('", "', HandlerId::ALL),
                $fields['id'],
            ));
        }

        if (\array_key_exists('available_instruments', $entry)) {
            self::assertOffersX402($entry['available_instruments']);
        }

        self::assertSameOrigin($fields['spec'], $gatewayBaseUrl, 'spec');
        self::assertSameOrigin($fields['schema'], $gatewayBaseUrl, 'schema');

        $fields['instrumentSchema'] = null;
        if (\array_key_exists('instrument_schemas', $entry)) {
            $instrumentSchema = self::requiredString(
                \is_array($entry['instrument_schemas']) ? ($entry['instrument_schemas'][0] ?? null) : null,
                'instrument_schemas',
            );
            self::assertSameOrigin($instrumentSchema, $gatewayBaseUrl, 'instrument schema');
            $fields['instrumentSchema'] = $instrumentSchema;
        }

        return $fields;
    }

    private static function requiredString(mixed $value, string $field): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new PrismApiException(sprintf('Prism handler entry field "%s" is missing or empty', $field));
        }

        return $value;
    }

    private static function assertOffersX402(mixed $instruments): void
    {
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

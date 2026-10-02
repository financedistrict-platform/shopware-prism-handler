<?php

declare(strict_types=1);

namespace Symfony\Contracts\HttpClient;

interface ResponseInterface
{
    public function getStatusCode(): int;

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(bool $throw = true): array;

    public function getContent(bool $throw = true): string;

    /**
     * @return array<mixed>
     */
    public function toArray(bool $throw = true): array;

    public function cancel(): void;

    public function getInfo(?string $type = null): mixed;
}

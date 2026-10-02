<?php

declare(strict_types=1);

namespace Symfony\Contracts\HttpClient;

interface HttpClientInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface;

    /**
     * @param ResponseInterface|iterable<ResponseInterface> $responses
     */
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface;

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static;
}

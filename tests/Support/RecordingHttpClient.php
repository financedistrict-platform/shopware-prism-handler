<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Support;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param list<array<string, mixed>> $bodies
     */
    public function __construct(
        private array $bodies,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $body = array_shift($this->bodies) ?? [];

        return new class(json_encode($body, \JSON_THROW_ON_ERROR)) implements ResponseInterface {
            public function __construct(private readonly string $content)
            {
            }

            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return $this->content;
            }

            public function toArray(bool $throw = true): array
            {
                return json_decode($this->content, true, 512, \JSON_THROW_ON_ERROR);
            }

            public function cancel(): void
            {
            }

            public function getInfo(?string $type = null): mixed
            {
                return null;
            }
        };
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('Streaming is not used.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }

    public function userAgent(int $index): string
    {
        return $this->requests[$index]['options']['headers']['User-Agent'];
    }
}

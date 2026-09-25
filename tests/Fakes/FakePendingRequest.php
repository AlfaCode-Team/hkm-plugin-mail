<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PendingRequestContract;

/**
 * The builder half of {@see FakeHttpClient}. Mutable rather than immutable —
 * the real adapter returns new instances, but a recorder only has to end up
 * with the right headers, so copying on every call would add nothing.
 */
final class FakePendingRequest implements PendingRequestContract
{
    /** @var array<string,string> */
    private array $headers = [];
    private ?int $timeout = null;

    public function __construct(private readonly FakeHttpClient $client) {}

    public function baseUrl(string $url): static { return $this; }

    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = (string) $value;
        }
        return $this;
    }

    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->headers['Authorization'] = trim($type . ' ' . $token);
        return $this;
    }

    public function withBasicAuth(string $username, string $password): static { return $this; }
    public function asJson(): static      { return $this->withHeader('Content-Type', 'application/json'); }
    public function asForm(): static      { return $this; }
    public function asMultipart(): static { return $this; }
    public function attach(string $name, string $contents, ?string $filename = null): static { return $this; }
    public function acceptJson(): static  { return $this->withHeader('Accept', 'application/json'); }

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function connectTimeout(int $seconds): static { return $this; }
    public function retry(int $times): static            { return $this; }
    public function retryMethods(array $methods): static { return $this; }

    public function get(string $url, array $query = []): HttpClientResponse   { return $this->dispatch('GET', $url, []); }
    public function post(string $url, array $data = []): HttpClientResponse   { return $this->dispatch('POST', $url, $data); }
    public function put(string $url, array $data = []): HttpClientResponse    { return $this->dispatch('PUT', $url, $data); }
    public function patch(string $url, array $data = []): HttpClientResponse  { return $this->dispatch('PATCH', $url, $data); }
    public function delete(string $url, array $data = []): HttpClientResponse { return $this->dispatch('DELETE', $url, $data); }

    public function send(string $method, string $url, array $options = []): HttpClientResponse
    {
        return $this->dispatch($method, $url, (array) ($options['json'] ?? []));
    }

    /** @param array<string,mixed> $body */
    private function dispatch(string $method, string $url, array $body): HttpClientResponse
    {
        return $this->client->record($method, $url, $body, $this->headers, $this->timeout);
    }
}

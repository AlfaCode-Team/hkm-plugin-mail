<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PendingRequestContract;

/**
 * An HttpClientPort that records what was sent and replays queued responses.
 *
 * MailtrapTransport builds its request through `pending()`, so the fake has to
 * implement the builder too — that is the surface under test: the URL the
 * stream resolves to, the bearer token, and the JSON body.
 */
final class FakeHttpClient implements HttpClientPort
{
    /** @var list<array{method: string, url: string, body: array<string,mixed>, headers: array<string,string>, timeout: ?int}> */
    public array $sent = [];

    /** @var list<HttpClientResponse|\Throwable> */
    private array $queued = [];

    /** @param array<string,mixed> $body */
    public function willRespond(int $status, array $body, string $contentType = 'application/json'): self
    {
        $this->queued[] = new HttpClientResponse($status, json_encode($body), ['Content-Type' => $contentType]);
        return $this;
    }

    public function willRespondRaw(int $status, string $body, string $contentType = 'text/html'): self
    {
        $this->queued[] = new HttpClientResponse($status, $body, ['Content-Type' => $contentType]);
        return $this;
    }

    public function willThrow(\Throwable $e): self
    {
        $this->queued[] = $e;
        return $this;
    }

    /** @return array{method: string, url: string, body: array<string,mixed>, headers: array<string,string>, timeout: ?int} */
    public function last(): array
    {
        return $this->sent[array_key_last($this->sent)] ?? throw new \RuntimeException('nothing was sent');
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    public function record(string $method, string $url, array $body, array $headers, ?int $timeout): HttpClientResponse
    {
        $this->sent[] = compact('method', 'url', 'body', 'headers', 'timeout');

        $next = array_shift($this->queued);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next ?? new HttpClientResponse(200, '{"success":true,"message_ids":[]}', ['Content-Type' => 'application/json']);
    }

    public function pending(): PendingRequestContract
    {
        return new FakePendingRequest($this);
    }

    // ── unused by the transport, present for the contract ────────────────────

    public function request(string $method, string $url, array $options = []): HttpClientResponse
    {
        return $this->record($method, $url, (array) ($options['json'] ?? []), (array) ($options['headers'] ?? []), null);
    }

    public function get(string $url, array $query = []): HttpClientResponse    { return $this->request('GET', $url); }
    public function post(string $url, array $data = []): HttpClientResponse    { return $this->request('POST', $url, ['json' => $data]); }
    public function put(string $url, array $data = []): HttpClientResponse     { return $this->request('PUT', $url, ['json' => $data]); }
    public function patch(string $url, array $data = []): HttpClientResponse   { return $this->request('PATCH', $url, ['json' => $data]); }
    public function delete(string $url, array $data = []): HttpClientResponse  { return $this->request('DELETE', $url, ['json' => $data]); }
}

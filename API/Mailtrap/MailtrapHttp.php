<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use Plugins\Mail\Domain\MailException;

/**
 * The one place a Mailtrap HTTP call is made.
 *
 * Every API class in this namespace goes through it, so authentication, the
 * host, timeouts, array-query normalisation and error translation are decided
 * once instead of in fifty methods. Outbound HTTP itself is the kernel
 * {@see HttpClientPort} — never a cURL handle opened here.
 *
 * TWO HOSTS, and the difference is not cosmetic:
 *   mailtrap.io               everything in this namespace (management APIs)
 *   send/bulk/sandbox.api.…   sending only — see {@see \Plugins\Mail\API\DTOs\MailtrapStream}
 *
 * Errors become {@see MailException} with the API's own `errors[]` text, so a
 * caller handles one exception type across delivery and management alike. The
 * API token is never included in a message.
 */
final class MailtrapHttp
{
    public const DEFAULT_HOST = 'mailtrap.io';

    private const USER_AGENT = 'hkm-plugin-mail (AlfacodeTeam PhpServicePlatform)';

    public function __construct(
        private readonly HttpClientPort $http,
        #[\SensitiveParameter]
        private readonly string $apiToken,
        private readonly string $host = self::DEFAULT_HOST,
        private readonly int $timeout = 30,
    ) {
        if (trim($apiToken) === '') {
            throw new MailException('MailtrapHttp: an API token is required (set MAILTRAP_API_TOKEN).');
        }
        if (preg_match('/[\r\n\x00]/', $apiToken) === 1) {
            throw new MailException('MailtrapHttp: the API token may not contain control characters.');
        }

        // The host is CONCATENATED into the request URL, and the bearer token
        // travels with whatever that resolves to. A value carrying '/', '@', a
        // scheme or whitespace would therefore send the account's credential to
        // a different origin entirely:
        //
        //   'attacker.test/x'            -> https://attacker.test/x/api/accounts
        //   'mailtrap.io@attacker.test'  -> userinfo, so the real host is attacker.test
        //
        // MailtrapSettings already refuses these, but it is not the only way in:
        // TransportFactory::mailtrapApiFromArray() builds this straight from
        // MAILTRAP_HOST. Validating at the choke point covers both doors.
        if (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) !== 1) {
            throw new MailException(
                "MailtrapHttp: invalid host '{$host}' — a bare hostname with an optional port, "
                . 'and nothing else (no scheme, path, credentials or whitespace).',
            );
        }
    }

    /**
     * var_dump()/print_r()-safe: the token is redacted.
     *
     * This object is reachable from every resource client
     * ({@see AbstractMailtrapApi::$http}), so without this a `print_r($api)`
     * anywhere — a debug dump, an error page, a log line — prints a live
     * credential in the clear. PHP applies __debugInfo() to nested objects too,
     * so redacting here covers the whole API surface.
     *
     * Matches the treatment {@see \Plugins\Mail\API\DTOs\MailtrapSettings}
     * and {@see \Plugins\Mail\API\DTOs\SmtpSettings} already give their
     * credentials. As there, the one function NOT covered is var_export(),
     * which reads real property values and has no redaction hook — do not
     * var_export() this class.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiToken' => '••••••',
            'host'     => $this->host,
            'timeout'  => $this->timeout,
            'http'     => $this->http::class,
        ];
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * @param  array<string,mixed> $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, null, $query);
    }

    /** @param ?array<string,mixed> $body @return array<mixed> */
    public function post(string $path, ?array $body = null): array
    {
        return $this->send('POST', $path, $body);
    }

    /** @param ?array<string,mixed> $body @return array<mixed> */
    public function put(string $path, ?array $body = null): array
    {
        return $this->send('PUT', $path, $body);
    }

    /** @param ?array<string,mixed> $body @return array<mixed> */
    public function patch(string $path, ?array $body = null): array
    {
        return $this->send('PATCH', $path, $body);
    }

    /** @param ?array<string,mixed> $body @return array<mixed> */
    public function delete(string $path, ?array $body = null): array
    {
        return $this->send('DELETE', $path, $body);
    }

    /**
     * Fetch a body that is NOT JSON.
     *
     * Several sandbox endpoints return the message itself — `body.txt`,
     * `body.html`, `body.raw`, `body.eml`, `body.htmlsource`. Putting those
     * through the JSON decoder yields null and the content is lost, so they
     * come back as the raw string instead.
     *
     * @param array<string,mixed> $query
     */
    public function getRaw(string $path, array $query = []): string
    {
        $response = $this->request('GET', $path, null, $query);

        if (!$response->ok()) {
            $decoded = $response->json();

            throw new MailException(
                sprintf(
                    'Mailtrap: HTTP %d from %s%s — %s',
                    $response->status(),
                    $this->host,
                    $path,
                    self::errorText(is_array($decoded) ? $decoded : [], $response),
                ),
                status: $response->status(),
            );
        }

        return $response->body();
    }

    /**
     * @param  ?array<string,mixed> $body
     * @param  array<string,mixed>  $query
     * @return array<mixed>
     */
    public function send(string $method, string $path, ?array $body = null, array $query = []): array
    {
        return $this->decode($this->request($method, $path, $body, $query), $path);
    }

    /**
     * @param ?array<string,mixed> $body
     * @param array<string,mixed>  $query
     */
    private function request(string $method, string $path, ?array $body, array $query): HttpClientResponse
    {
        $url = 'https://' . $this->host . $path . $this->queryString($query);

        try {
            $request = $this->http->pending()
                ->withToken($this->apiToken)
                ->withHeader('User-Agent', self::USER_AGENT)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout);

            $response = $body === null && $method !== 'GET'
                // An empty body is NOT the same as {}: several endpoints (a
                // campaign start, an inbox clean) take no body at all, and the
                // API rejects a JSON object where it expects none.
                ? $request->send($method, $url)
                : $request->send($method, $url, $body === null ? [] : ['json' => $body]);
        } catch (\Throwable $e) {
            throw new MailException(
                'Mailtrap: request to ' . $this->host . $path . ' failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return $response;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Mailtrap rejects PHP's default numerically-indexed array syntax
     * (`inbox_ids[0]=1`); it wants `inbox_ids[]=1`. `http_build_query` has no
     * flag for that, so the indices are stripped afterwards — the same fix the
     * official SDK applies, for the same reason.
     *
     * @param array<string,mixed> $query
     */
    private function queryString(array $query): string
    {
        if ($query === []) {
            return '';
        }

        $encoded = (string) preg_replace('/%5B\d+%5D/iU', '%5B%5D', http_build_query($query, '', '&'));

        return $encoded === '' ? '' : '?' . $encoded;
    }

    /** @return array<mixed> */
    private function decode(HttpClientResponse $response, string $path): array
    {
        $decoded = $response->json();

        if (!$response->ok()) {
            throw new MailException(
                sprintf(
                    'Mailtrap: HTTP %d from %s%s — %s',
                    $response->status(),
                    $this->host,
                    $path,
                    self::errorText(is_array($decoded) ? $decoded : [], $response),
                ),
                status: $response->status(),
            );
        }

        // Some endpoints (a delete, a reset) answer 204 with no body at all.
        if ($decoded === null) {
            return [];
        }
        if (!is_array($decoded)) {
            return ['data' => $decoded];
        }
        if (($decoded['success'] ?? true) === false) {
            // A 2xx carrying {"success": false}. The status is passed through
            // as-is rather than translated to a 4xx: inventing a status the API
            // did not send would be a lie, and a 2xx reads as "not known to be
            // permanent", which errs towards retrying — the safe direction.
            throw new MailException(
                'Mailtrap: ' . self::errorText($decoded, $response),
                status: $response->status(),
            );
        }

        return $decoded;
    }

    /**
     * Mailtrap reports faults as `{"errors": [...]}`, sometimes
     * `{"error": "..."}`, and occasionally as a field→messages map from the
     * Rails validation layer. Falls back to the raw body (truncated) when the
     * response is none of those — an HTML page from a proxy, most often, where
     * saying so beats "unknown error".
     *
     * @param array<mixed> $body
     */
    public static function errorText(array $body, ?HttpClientResponse $response = null): string
    {
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            return implode('; ', array_map(self::stringify(...), $errors));
        }
        if (is_string($errors) && $errors !== '') {
            return $errors;
        }
        if (is_string($body['error'] ?? null) && $body['error'] !== '') {
            return $body['error'];
        }

        $raw = trim((string) $response?->body());

        return $raw === '' ? 'no error detail in the response' : mb_substr($raw, 0, 500);
    }

    private static function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}

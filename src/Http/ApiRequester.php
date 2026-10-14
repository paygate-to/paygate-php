<?php

declare(strict_types=1);

namespace PayGate\Http;

use PayGate\Exception\ApiException;
use PayGate\Exception\TransportException;

/**
 * Sends API requests and decodes the JSON answers.
 *
 * @internal
 */
final class ApiRequester
{
    /** @var HttpClientInterface */
    private $http;

    /** @var string */
    private $apiHost;

    /** @var string */
    private $userAgent;

    /** @var int */
    private $retries;

    public function __construct(HttpClientInterface $http, string $apiHost, string $userAgent, int $retries)
    {
        $this->http = $http;
        $this->apiHost = $apiHost;
        $this->userAgent = $userAgent;
        $this->retries = max(0, $retries);
    }

    public function apiHost(): string
    {
        return $this->apiHost;
    }

    /**
     * GET https://{api host}/{path}?{query}. Query values are percent-encoded, except those
     * listed in $raw, which PayGate.to already returned encoded (address_in, ipn_token...).
     *
     * @param array<string,string|int|float|null> $query
     * @param array<int,string>                   $raw
     * @param bool                                $retry false for requests that are not safe to repeat
     *
     * @return array<mixed>
     */
    public function get(string $path, array $query = [], array $raw = [], bool $retry = true): array
    {
        $url = $this->url($path, $query, $raw);
        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->send('GET', $url, $this->headers());
                break;
            } catch (TransportException $e) {
                if (!$retry || $attempt++ >= $this->retries) {
                    throw $e;
                }
                usleep(250000 * $attempt);
            }
        }

        return $this->decode($response, $url);
    }

    /**
     * POST a JSON body. Never retried, so a payment is never created twice.
     *
     * @param array<string,mixed>                 $payload
     * @param array<string,string|int|float|null> $query
     *
     * @return array<mixed>
     */
    public function postJson(string $path, array $payload, array $query = []): array
    {
        $url = $this->url($path, $query, []);
        // serialize_precision -1 writes 0.58 as 0.58 even where php.ini sets 17 digits.
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        } finally {
            ini_set('serialize_precision', (string) $precision);
        }
        if ($body === false) {
            throw new ApiException('The request body could not be encoded as JSON.', 0, '', $url);
        }
        $headers = $this->headers();
        $headers['Content-Type'] = 'application/json';
        $response = $this->http->send('POST', $url, $headers, $body);

        return $this->decode($response, $url);
    }

    /**
     * @param array<string,string|int|float|null> $query
     * @param array<int,string>                   $raw
     */
    public function url(string $path, array $query = [], array $raw = []): string
    {
        $url = 'https://' . $this->apiHost . '/' . ltrim($path, '/');

        return $url . self::queryString($query, $raw);
    }

    /**
     * @param array<string,string|int|float|null> $query
     * @param array<int,string>                   $raw
     */
    public static function queryString(array $query, array $raw = []): string
    {
        $parts = [];
        foreach ($query as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $value = (string) $value;
            $parts[] = rawurlencode((string) $name) . '=' . (in_array($name, $raw, true) ? self::keepEncoded($value) : rawurlencode($value));
        }

        return $parts === [] ? '' : '?' . implode('&', $parts);
    }

    /**
     * PayGate.to returns some values already percent-encoded (address_in, payment_token, ipn_token).
     * A value made only of unreserved characters and %XX escapes is passed through unchanged;
     * anything else is encoded, so "&" or "=" can never add a parameter.
     */
    public static function keepEncoded(string $value): string
    {
        if (preg_match('/%[0-9A-Fa-f]{2}/', $value) && preg_match('/^(?:[A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})+$/', $value)) {
            return $value;
        }

        return rawurlencode($value);
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return ['Accept' => 'application/json', 'User-Agent' => $this->userAgent];
    }

    /**
     * @return array<mixed>
     */
    private function decode(HttpResponse $response, string $url): array
    {
        $status = $response->getStatusCode();
        $body = $response->getBody();
        $data = json_decode($body, true);
        $redacted = self::redact($url);

        if ($status < 200 || $status >= 300) {
            $message = is_array($data) ? self::errorMessage($data) : '';
            throw new ApiException(
                sprintf('PayGate.to answered HTTP %d for %s%s', $status, $redacted, $message !== '' ? ': ' . $message : '.'),
                $status,
                $body,
                $redacted
            );
        }
        if (!is_array($data)) {
            throw new ApiException('PayGate.to returned a response that is not JSON for ' . $redacted . '.', $status, $body, $redacted);
        }
        if (isset($data['status']) && is_string($data['status']) && strtolower($data['status']) === 'error') {
            $message = self::errorMessage($data);
            throw new ApiException('PayGate.to reported an error for ' . $redacted . ($message !== '' ? ': ' . $message : '.'), $status, $body, $redacted);
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private static function errorMessage(array $data): string
    {
        foreach (['message', 'error', 'detail'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return '';
    }

    private static function redact(string $url): string
    {
        $pos = strpos($url, '?');

        return $pos === false ? $url : substr($url, 0, $pos);
    }
}

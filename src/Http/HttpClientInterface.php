<?php

declare(strict_types=1);

namespace PayGate\Http;

use PayGate\Exception\TransportException;

/**
 * Minimal HTTP client used by the SDK. Implement it to route requests through your own
 * stack (Guzzle, Symfony HttpClient, a proxy, a test double...).
 */
interface HttpClientInterface
{
    /**
     * Send a request and return the response, whatever its status code.
     *
     * @param string               $method  "GET" or "POST"
     * @param string               $url     Absolute https URL, query string included
     * @param array<string,string> $headers Request headers
     * @param string|null          $body    Request body (POST only)
     *
     * @throws TransportException when no response could be obtained
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}

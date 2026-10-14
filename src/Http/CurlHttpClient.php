<?php

declare(strict_types=1);

namespace PayGate\Http;

use PayGate\Exception\TransportException;

/**
 * Default HTTP client: cURL when the extension is loaded, PHP streams otherwise.
 * TLS certificates are always verified.
 */
final class CurlHttpClient implements HttpClientInterface
{
    /** @var float */
    private $timeout;

    /** @var float */
    private $connectTimeout;

    public function __construct(float $timeout = 30.0, float $connectTimeout = 10.0)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (function_exists('curl_init')) {
            return $this->sendWithCurl($method, $url, $headers, $body);
        }

        return $this->sendWithStreams($method, $url, $headers, $body);
    }

    /**
     * @param array<string,string> $headers
     */
    private function sendWithCurl(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $handle = curl_init();
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new TransportException('Request to ' . self::redact($url) . ' failed: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, (string) $responseBody);
    }

    /**
     * @param array<string,string> $headers
     */
    private function sendWithStreams(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $lines,
                'content' => $body === null ? '' : $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            $error = error_get_last();
            $message = (string) ($error['message'] ?? 'unknown error');
            // PHP quotes the full URL in stream errors: drop every query string from the message.
            $message = (string) preg_replace('#(https?://[^\s?()]*)\?[^\s()]*#i', '$1', $message);
            throw new TransportException('Request to ' . self::redact($url) . ' failed: ' . $message);
        }

        $status = 0;
        /** @var array<int,string> $http_response_header */
        if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $match)) {
            $status = (int) $match[1];
        }

        return new HttpResponse($status, $responseBody);
    }

    /** Drop the query string from URLs placed in error messages (it holds wallets and callbacks). */
    private static function redact(string $url): string
    {
        $pos = strpos($url, '?');

        return $pos === false ? $url : substr($url, 0, $pos);
    }
}

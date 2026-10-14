<?php

declare(strict_types=1);

namespace PayGate\Tests;

use PayGate\Exception\TransportException;
use PayGate\Http\HttpClientInterface;
use PayGate\Http\HttpResponse;

/** Records requests and replays queued responses. */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int,array{method: string, url: string, headers: array<string,string>, body: ?string}> */
    public $requests = [];

    /** @var array<int,HttpResponse|TransportException> */
    private $queue = [];

    /** @param array<mixed>|string $body */
    public function queue($body, int $status = 200): self
    {
        $this->queue[] = new HttpResponse($status, is_string($body) ? $body : (string) json_encode($body));

        return $this;
    }

    public function queueFailure(string $message = 'connection refused'): self
    {
        $this->queue[] = new TransportException($message);

        return $this;
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->queue === []) {
            throw new \LogicException('No response queued for ' . $method . ' ' . $url);
        }
        $next = array_shift($this->queue);
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /** @return array{method: string, url: string, headers: array<string,string>, body: ?string} */
    public function last(): array
    {
        return $this->requests[count($this->requests) - 1];
    }

    /** Raw query string of the last request. */
    public function lastQuery(): string
    {
        return (string) parse_url($this->last()['url'], PHP_URL_QUERY);
    }

    /** Path of the last request. */
    public function lastPath(): string
    {
        return (string) parse_url($this->last()['url'], PHP_URL_PATH);
    }
}

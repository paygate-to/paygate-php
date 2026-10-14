<?php

declare(strict_types=1);

namespace PayGate\Http;

/**
 * A plain HTTP response: status code and body.
 */
final class HttpResponse
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $body;

    public function __construct(int $statusCode, string $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}

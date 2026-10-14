<?php

declare(strict_types=1);

namespace PayGate\Exception;

/**
 * PayGate.to answered, but with an error: a non-2xx status, a body that is not JSON,
 * or a JSON body that reports a failure (for example "status": "error").
 */
class ApiException extends PayGateException
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $responseBody;

    /** @var string */
    private $url;

    public function __construct(string $message, int $statusCode = 0, string $responseBody = '', string $url = '')
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->url = $url;
    }

    /** HTTP status code of the response (0 when not applicable). */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Raw response body, useful when contacting PayGate.to support. */
    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    /** The URL that was requested. */
    public function getUrl(): string
    {
        return $this->url;
    }
}

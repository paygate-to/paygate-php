<?php

declare(strict_types=1);

namespace PayGate\Exception;

/**
 * The HTTP request could not be completed (DNS, TLS, timeout, connection refused...).
 */
class TransportException extends PayGateException
{
}

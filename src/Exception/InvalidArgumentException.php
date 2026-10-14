<?php

declare(strict_types=1);

namespace PayGate\Exception;

/**
 * A value passed to the SDK is missing or malformed (empty wallet, bad amount, unknown host...).
 * Thrown before any request is sent.
 */
class InvalidArgumentException extends PayGateException
{
}

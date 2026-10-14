<?php

declare(strict_types=1);

namespace PayGate\Support;

use PayGate\Exception\InvalidArgumentException;

/**
 * Normalises the domains passed to the client ("https://api-shop-1a2b.paygate.to/" -> "api-shop-1a2b.paygate.to").
 *
 * @internal
 */
final class Host
{
    public static function normalise(string $value, string $label): string
    {
        $host = strtolower(trim($value));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = rtrim(explode('/', $host, 2)[0], '.');

        if ($host === '' || !preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            throw new InvalidArgumentException(sprintf('%s "%s" is not a valid host name.', $label, $value));
        }

        return $host;
    }
}

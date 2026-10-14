<?php

declare(strict_types=1);

namespace PayGate\Support;

use PayGate\Exception\InvalidArgumentException;

/**
 * Turns amounts into plain decimal strings ("12.5", never "1.25E+1" or "1,234.50").
 *
 * @internal
 */
final class Amount
{
    /**
     * @param int|float|string $value
     */
    public static function format($value, string $label = 'Amount'): string
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException($label . ' must be a finite number.');
            }
            $text = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            throw new InvalidArgumentException($label . ' must be a number.');
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException(sprintf('%s "%s" must be a plain positive number such as 49.90.', $label, $text));
        }
        if ((float) $text <= 0) {
            throw new InvalidArgumentException($label . ' must be greater than zero.');
        }

        return $text;
    }
}

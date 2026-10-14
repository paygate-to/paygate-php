<?php

declare(strict_types=1);

namespace PayGate\Support;

use PayGate\Exception\InvalidArgumentException;

/**
 * Exact arithmetic on decimal strings, without bcmath and without floats.
 *
 * @internal
 */
final class Decimal
{
    /**
     * A non-negative decimal string: "0.05", "1e-7" and "1.5E+3" become "0.05", "0.0000001", "1500".
     * Null when the text is not a number.
     */
    public static function plain(string $text): ?string
    {
        $text = trim($text);
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $text, $m)) {
            return null;
        }
        $int = $m[1];
        $frac = $m[2] ?? '';
        $exp = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        if ($exp > 0) {
            $frac = str_pad($frac, $exp, '0');
            $int .= substr($frac, 0, $exp);
            $frac = substr($frac, $exp);
        } elseif ($exp < 0) {
            $int = str_pad($int, -$exp + 1, '0', STR_PAD_LEFT);
            $frac = substr($int, $exp) . $frac;
            $int = substr($int, 0, $exp);
        }

        return self::build($int, $frac);
    }

    /** Exact product of two non-negative decimal strings. */
    public static function multiply(string $a, string $b): string
    {
        $x = self::plain($a);
        $y = self::plain($b);
        if ($x === null || $y === null) {
            throw new InvalidArgumentException(sprintf('"%s" × "%s" is not a product of decimal numbers.', $a, $b));
        }
        [$xi, $xf] = array_pad(explode('.', $x, 2), 2, '');
        [$yi, $yf] = array_pad(explode('.', $y, 2), 2, '');
        $digits = self::multiplyIntegers($xi . $xf, $yi . $yf);
        $decimals = strlen($xf) + strlen($yf);
        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);

        return self::build(substr($digits, 0, strlen($digits) - $decimals), $decimals > 0 ? substr($digits, -$decimals) : '');
    }

    /** Rounds half up to $scale decimals. */
    public static function round(string $value, int $scale): string
    {
        $plain = self::plain($value);
        if ($plain === null || $scale < 0) {
            throw new InvalidArgumentException(sprintf('Cannot round "%s" to %d decimals.', $value, $scale));
        }
        [$int, $frac] = array_pad(explode('.', $plain, 2), 2, '');
        if (strlen($frac) <= $scale) {
            return $plain;
        }
        $roundUp = $frac[$scale] >= '5';
        $kept = $int . substr($frac, 0, $scale);
        if ($roundUp) {
            $kept = self::addOne($kept);
        }
        $kept = str_pad($kept, $scale + 1, '0', STR_PAD_LEFT);

        return self::build(substr($kept, 0, strlen($kept) - $scale), $scale > 0 ? substr($kept, -$scale) : '');
    }

    private static function multiplyIntegers(string $a, string $b): string
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '' || $b === '') {
            return '0';
        }
        $result = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $sum = (int) $a[$i] * (int) $b[$j] + $result[$i + $j + 1];
                $result[$i + $j + 1] = $sum % 10;
                $result[$i + $j] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    private static function addOne(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0 && $digits[$i] === '9') {
            $digits[$i] = '0';
            $i--;
        }

        return $i < 0 ? '1' . $digits : substr_replace($digits, (string) ((int) $digits[$i] + 1), $i, 1);
    }

    private static function build(string $int, string $frac): string
    {
        $int = ltrim($int, '0');
        $frac = rtrim($frac, '0');

        return ($int === '' ? '0' : $int) . ($frac === '' ? '' : '.' . $frac);
    }
}

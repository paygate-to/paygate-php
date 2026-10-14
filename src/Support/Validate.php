<?php

declare(strict_types=1);

namespace PayGate\Support;

use PayGate\Callback\CallbackData;
use PayGate\Exception\InvalidArgumentException;

/**
 * Input checks shared by the card and crypto APIs.
 *
 * @internal
 */
final class Validate
{
    /** A wallet address: non-empty, no spaces. Its format is checked by PayGate.to. */
    public static function wallet(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\s/', $value)) {
            throw new InvalidArgumentException($label . ' is empty or contains spaces.');
        }

        return $value;
    }

    /** An absolute http(s) callback URL with at least one query parameter (the order reference). */
    public static function callback(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('The callback URL must be an absolute http(s) URL.');
        }
        if (!isset($parts['query']) || $parts['query'] === '') {
            throw new InvalidArgumentException('The callback URL needs at least one parameter unique to the order, e.g. ?order=1234.');
        }
        foreach (explode('&', $parts['query']) as $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (in_array($name, CallbackData::PAYGATE_PARAMS, true)) {
                throw new InvalidArgumentException(sprintf('The callback URL must not contain "%s": PayGate.to adds that parameter itself.', $name));
            }
        }

        return $url;
    }

    /**
     * @param int|float|string|null $value
     */
    public static function share($value, string $label): string
    {
        if ($value === null) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        $text = Amount::format($value, $label);
        if ((float) $text >= 1) {
            throw new InvalidArgumentException($label . ' is a share: use 0.01 for 1%.');
        }

        return $text;
    }

    /**
     * @param array<string,int|float|string> $shares
     *
     * @return array<string,float>
     */
    public static function shares(array $shares): array
    {
        if ($shares === [] || count($shares) > 19) {
            throw new InvalidArgumentException('Give between 1 and 19 payout wallets.');
        }
        $out = [];
        foreach ($shares as $walletAddress => $share) {
            $out[self::wallet((string) $walletAddress, 'Payout wallet')] = (float) self::share($share, 'Share of ' . $walletAddress);
        }
        self::assertShares(array_map('strval', array_values($out)));

        return $out;
    }

    /**
     * PayGate.to keeps 1%, so the shares must add up to exactly 0.99.
     *
     * @param array<int,string> $shares
     */
    public static function assertShares(array $shares): void
    {
        $total = 0;
        foreach ($shares as $share) {
            $total += (int) round(((float) $share) * 10000);
        }
        if ($total !== 9900) {
            throw new InvalidArgumentException(sprintf('The shares add up to %s; they must add up to exactly 0.99 (PayGate.to keeps 1%%).', rtrim(rtrim(number_format($total / 10000, 4, '.', ''), '0'), '.')));
        }
    }

    /**
     * @param array<string,mixed> $branding
     *
     * @return array<string,string>
     */
    public static function branding(array $branding): array
    {
        $allowed = ['domain', 'logo', 'background', 'theme', 'button'];
        $out = [];
        foreach ($branding as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Unknown branding option "%s". Use: %s.', $name, implode(', ', $allowed)));
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if ($name === 'domain') {
                $value = Host::normalise($value, 'Branding domain');
            }
            $out[$name] = $value;
        }

        return $out;
    }
}

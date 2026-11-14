<?php

declare(strict_types=1);

namespace PayGate\Crypto;

/**
 * XRP Ledger payout addresses.
 *
 * PayGate.to accepts an XRP payout wallet as a classic address ("r…"), a classic address with a
 * destination tag ("r…?dt=777") or an X-address ("X…", which carries the tag). In the callback's
 * address_out it always writes the classic form: "r…?dt=777", or "r…" when there is no tag.
 * payoutForm() turns your wallet into that form so you can compare the two.
 */
final class XrpAddress
{
    private const ALPHABET = 'rpshnaf39wBUDNEGHJKLM4PQRST7VWXYZ2bcdeCg65jkm8oFqi1tuvAxyz';

    /** Mainnet X-address prefix (XLS-5d). */
    private const X_PREFIX = "\x05\x44";

    /**
     * The form PayGate.to uses in address_out: an X-address becomes "r…?dt=TAG" ("r…" without a
     * tag). Any other value is returned trimmed and unchanged.
     */
    public static function payoutForm(string $address): string
    {
        $address = trim($address);
        $decoded = self::decodeXAddress($address);
        if ($decoded === null) {
            return $address;
        }

        return $decoded['tag'] === null ? $decoded['classic'] : $decoded['classic'] . '?dt=' . $decoded['tag'];
    }

    /**
     * Decode a mainnet X-address.
     *
     * @return array{classic:string,tag:?int}|null null if the value is not a valid mainnet X-address
     */
    public static function decodeXAddress(string $address): ?array
    {
        $address = trim($address);
        if (strlen($address) < 40 || strlen($address) > 50 || $address[0] !== 'X') {
            return null;
        }
        $bytes = self::base58Decode($address);
        if ($bytes === null || strlen($bytes) !== 35) {
            return null;
        }
        $payload = substr($bytes, 0, 31);
        if (!hash_equals(self::checksum($payload), substr($bytes, 31))) {
            return null;
        }
        if (substr($payload, 0, 2) !== self::X_PREFIX) {
            return null;
        }
        $flag = ord($payload[22]);
        $tagBytes = substr($payload, 23, 8);
        if ($flag === 0 && $tagBytes === str_repeat("\0", 8)) {
            $tag = null;
        } elseif ($flag === 1 && substr($tagBytes, 4) === str_repeat("\0", 4)) {
            $tag = unpack('V', substr($tagBytes, 0, 4))[1];
        } else {
            return null;
        }
        $versioned = "\0" . substr($payload, 2, 20);

        return ['classic' => self::base58Encode($versioned . self::checksum($versioned)), 'tag' => $tag];
    }

    private static function checksum(string $data): string
    {
        return substr(hash('sha256', hash('sha256', $data, true), true), 0, 4);
    }

    private static function base58Decode(string $text): ?string
    {
        $digits = []; // little-endian, base 256
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $carry = strpos(self::ALPHABET, $text[$i]);
            if ($carry === false) {
                return null;
            }
            foreach ($digits as $j => $digit) {
                $carry += $digit * 58;
                $digits[$j] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $digits[] = $carry & 0xff;
                $carry >>= 8;
            }
        }
        $zeros = strspn($text, self::ALPHABET[0]);

        return str_repeat("\0", $zeros) . implode('', array_map('chr', array_reverse($digits)));
    }

    private static function base58Encode(string $bytes): string
    {
        $digits = []; // little-endian, base 58
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $carry = ord($bytes[$i]);
            foreach ($digits as $j => $digit) {
                $carry += $digit << 8;
                $digits[$j] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }
        $out = str_repeat(self::ALPHABET[0], strspn($bytes, "\0"));
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $out .= self::ALPHABET[$digits[$i]];
        }

        return $out;
    }
}

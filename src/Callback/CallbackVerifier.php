<?php

declare(strict_types=1);

namespace PayGate\Callback;

use PayGate\Exception\InvalidArgumentException;

/**
 * Checks PayGate.to's RSA signature on a payment callback.
 *
 * Every callback carries two headers:
 *   X-PayGate-Signature  base64 RSA-2048 signature (SHA-256, PKCS#1 v1.5)
 *   X-PayGate-Key-Id     the key that signed it ("v1")
 *
 * The signed string is the callback URL you registered, cut before "?", followed by "?" and
 * the query string exactly as PayGate.to sent it. Nothing is rebuilt from parsed parameters,
 * so any change to value_coin, txid_out or your own order parameter breaks the signature.
 *
 * The published key (https://paygate.to/docs/paygate_callback_public.pem) is bundled as "v1".
 *
 *     $result = $paygate->callbacks()->verifyRequest('https://shop.example/paygate-callback.php');
 *     if (!$result->isValid()) {
 *         http_response_code(403);
 *         exit;
 *     }
 *
 * Always fail closed: an invalid or missing signature means the callback is ignored.
 */
final class CallbackVerifier
{
    /** @var array<string,string> key id => PEM */
    private $keys = [];

    /** @var callable|null */
    private $keyResolver;

    /**
     * Key ids the resolver did not know => time of that lookup. The resolver is asked again after
     * 10 minutes, so a forged key id cannot block a real key that is published later.
     *
     * @var array<string,int>
     */
    private $missing = [];

    /**
     * @param array<string,string>|null $keys        Key id => PEM public key. Null uses the bundled "v1" key.
     * @param callable|null             $keyResolver fn(string $keyId): ?string, asked for key ids not in $keys
     *                                               (lets you add a future key without upgrading the SDK).
     *                                               An unknown id is asked again after 10 minutes.
     */
    public function __construct(?array $keys = null, ?callable $keyResolver = null)
    {
        if ($keys === null) {
            $keys = ['v1' => self::bundledKey()];
        }
        foreach ($keys as $id => $pem) {
            $this->addKey((string) $id, (string) $pem);
        }
        $this->keyResolver = $keyResolver;
    }

    /** Adds or replaces a public key. */
    public function addKey(string $keyId, string $pem): self
    {
        if (!self::validKeyId($keyId)) {
            throw new InvalidArgumentException('Key ids are 1 to 32 letters, digits, "_" or "-".');
        }
        if (strpos($pem, '-----BEGIN PUBLIC KEY-----') === false) {
            throw new InvalidArgumentException('Key "' . $keyId . '" is not a PEM public key.');
        }
        $this->keys[$keyId] = $pem;
        unset($this->missing[$keyId]);

        return $this;
    }

    /**
     * Verifies a callback.
     *
     * @param string      $registeredCallbackUrl The callback URL you passed when creating the wallet
     *                                           (only its scheme, host and path are used).
     * @param string|null $rawQuery              The query string as received, undecoded (null or "" when there is none).
     * @param string|null $signature             The X-PayGate-Signature header.
     * @param string|null $keyId                 The X-PayGate-Key-Id header ("v1" when absent).
     */
    public function verify(string $registeredCallbackUrl, ?string $rawQuery, ?string $signature, ?string $keyId = null): VerificationResult
    {
        $signature = trim((string) $signature);
        if ($signature === '') {
            return VerificationResult::invalid('signature_absent');
        }
        if (!function_exists('openssl_verify')) {
            return VerificationResult::invalid('openssl_unavailable');
        }

        $keyId = trim((string) $keyId);
        if ($keyId === '') {
            $keyId = 'v1';
        }
        $pem = $this->keyFor($keyId);
        if ($pem === null) {
            return VerificationResult::invalid('unknown_key_id', $keyId);
        }
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return VerificationResult::invalid('bad_public_key', $keyId);
        }

        $raw = base64_decode($signature, true);
        if ($raw === false || $raw === '') {
            return VerificationResult::invalid('signature_not_base64', $keyId);
        }

        $base = self::callbackBase($registeredCallbackUrl);
        if ($base === null) {
            return VerificationResult::invalid('no_registered_callback', $keyId);
        }

        $rawQuery = (string) $rawQuery;
        if (strncmp($rawQuery, '?', 1) === 0) {
            $rawQuery = substr($rawQuery, 1);
        }
        $signed = $rawQuery === '' ? $base : $base . '?' . $rawQuery;

        $result = openssl_verify($signed, $raw, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result === 1) {
            return VerificationResult::valid($keyId);
        }

        return VerificationResult::invalid($result === -1 ? 'openssl_error' : 'signature_mismatch', $keyId);
    }

    /**
     * Verifies the current PHP request: headers from $_SERVER, raw query from REQUEST_URI
     * (QUERY_STRING as a fallback).
     *
     * @param array<string,mixed>|null $server Defaults to $_SERVER.
     */
    public function verifyRequest(string $registeredCallbackUrl, ?array $server = null): VerificationResult
    {
        $server = $server ?? $_SERVER;

        return $this->verify(
            $registeredCallbackUrl,
            self::rawQueryFrom($server),
            isset($server['HTTP_X_PAYGATE_SIGNATURE']) ? (string) $server['HTTP_X_PAYGATE_SIGNATURE'] : null,
            isset($server['HTTP_X_PAYGATE_KEY_ID']) ? (string) $server['HTTP_X_PAYGATE_KEY_ID'] : null
        );
    }

    /**
     * The undecoded query string of a request.
     *
     * @param array<string,mixed> $server
     */
    public static function rawQueryFrom(array $server): string
    {
        if (isset($server['REQUEST_URI'])) {
            $uri = (string) $server['REQUEST_URI'];
            $hash = strpos($uri, '#');
            if ($hash !== false) {
                $uri = substr($uri, 0, $hash);
            }
            $mark = strpos($uri, '?');

            return $mark === false ? '' : substr($uri, $mark + 1);
        }

        return isset($server['QUERY_STRING']) ? (string) $server['QUERY_STRING'] : '';
    }

    /** Scheme, host, port and path of a URL; null if it is not an absolute http(s) URL. */
    public static function callbackBase(string $url): ?string
    {
        $url = trim($url);
        $cut = strcspn($url, '?#');
        $base = substr($url, 0, $cut);
        if (!preg_match('#^https?://[^/\s]+#i', $base)) {
            return null;
        }

        return $base;
    }

    /** Path of the bundled PayGate.to public key. */
    public static function bundledKeyPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/keys/paygate_callback_public_v1.pem';
    }

    private static function bundledKey(): string
    {
        $pem = @file_get_contents(self::bundledKeyPath());
        if (!is_string($pem) || $pem === '') {
            throw new InvalidArgumentException('The bundled PayGate.to public key is missing: ' . self::bundledKeyPath());
        }

        return $pem;
    }

    private function keyFor(string $keyId): ?string
    {
        if (!self::validKeyId($keyId)) {
            return null;
        }
        if (isset($this->keys[$keyId])) {
            return $this->keys[$keyId];
        }
        if ($this->keyResolver !== null && (!isset($this->missing[$keyId]) || time() - $this->missing[$keyId] >= 600)) {
            $pem = ($this->keyResolver)($keyId);
            if (is_string($pem) && $pem !== '') {
                $this->keys[$keyId] = $pem;
                unset($this->missing[$keyId]);

                return $pem;
            }
            if (count($this->missing) >= 256) {
                array_shift($this->missing);
            }
            $this->missing[$keyId] = time();
        }

        return null;
    }

    private static function validKeyId(string $keyId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId);
    }
}

<?php

declare(strict_types=1);

namespace PayGate\Callback;

use PayGate\Exception\InvalidArgumentException;

/**
 * A secret token in your callback URL, tying each callback to one order.
 *
 * The RSA signature proves a callback came from PayGate.to. This token additionally proves the
 * order reference in the URL is one your server issued, so a payment made to one order's
 * address cannot be replayed against another. Keep the secret on your server only.
 *
 *     $tokens = new CallbackToken(getenv('PAYGATE_CALLBACK_SECRET'));
 *     $callback = $tokens->callbackUrl('https://shop.example/paygate-callback.php', ['order' => '1234']);
 *     // https://shop.example/paygate-callback.php?order=1234&token=9c1f...
 *
 *     // in the callback handler:
 *     $tokens->verify($data->get('order'), $data->get('token'))  // true or false
 *
 * The token is deterministic, so building the URL again for the same order gives the same
 * callback and PayGate.to returns the same payment address.
 */
final class CallbackToken
{
    /** @var string */
    private $secret;

    /** @var string */
    private $param;

    /**
     * @param string $secret At least 32 characters, e.g. bin2hex(random_bytes(32)), stored in your config.
     * @param string $param  Name of the token parameter (default "token").
     */
    public function __construct(string $secret, string $param = 'token')
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The callback secret must be at least 32 characters. Generate one with bin2hex(random_bytes(32)).');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $param) || in_array($param, CallbackData::PAYGATE_PARAMS, true)) {
            throw new InvalidArgumentException('The token parameter name may use letters, digits and "_" only.');
        }
        $this->secret = $secret;
        $this->param = $param;
    }

    /** HMAC-SHA256 of the order reference, hex encoded. */
    public function sign(string $orderRef): string
    {
        if ($orderRef === '') {
            throw new InvalidArgumentException('The order reference is empty.');
        }

        return hash_hmac('sha256', 'paygate-order:' . $orderRef, $this->secret);
    }

    /** Constant-time check of a token received in a callback. */
    public function verify(?string $orderRef, ?string $token): bool
    {
        if ($orderRef === null || $orderRef === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($this->sign($orderRef), $token);
    }

    /**
     * Your callback URL with the order parameters and the token appended.
     *
     * @param array<string,string|int> $params Order parameters. The first one is the order reference that is signed.
     */
    public function callbackUrl(string $baseUrl, array $params): string
    {
        if ($params === []) {
            throw new InvalidArgumentException('Pass the order reference, e.g. ["order" => "1234"].');
        }
        if (array_key_exists($this->param, $params)) {
            throw new InvalidArgumentException('"' . $this->param . '" is reserved for the token.');
        }
        foreach (array_keys($params) as $name) {
            if (in_array((string) $name, CallbackData::PAYGATE_PARAMS, true)) {
                throw new InvalidArgumentException('"' . $name . '" is a parameter PayGate.to adds to the callback; use another name.');
            }
        }
        $orderRef = (string) reset($params);
        $params[$this->param] = $this->sign($orderRef);

        $parts = [];
        foreach ($params as $name => $value) {
            $parts[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }
        $glue = strpos($baseUrl, '?') === false ? '?' : '&';

        return $baseUrl . $glue . implode('&', $parts);
    }

    /** Name of the token parameter. */
    public function getParam(): string
    {
        return $this->param;
    }
}

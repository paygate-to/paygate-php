<?php

declare(strict_types=1);

namespace PayGate\Callback;

use PayGate\Exception\InvalidArgumentException;
use PayGate\Support\Decimal;

/**
 * The payment data PayGate.to adds to your callback URL.
 *
 *     $data = PayGate\Callback\CallbackData::fromRequest();
 *     $data->getValueCoin();      // "105.6"  amount received (card: USDC; crypto: in the paid coin)
 *     $data->getCoin();           // "polygon_usdc", "btc", "erc20_usdc"...
 *     $data->getTxidIn();         // incoming transaction: use it to ignore repeated callbacks
 *     $data->getPayouts();        // ["0x0425...": "96.2148", ...] exact amount each address received
 *     $data->get('order');        // your own parameter
 *
 * Read the data only after CallbackVerifier has accepted the signature.
 *
 * PayGate.to appends its parameters after the ones in your callback URL. When a name appears more
 * than once, the last value (PayGate.to's) is used, as in $_GET, so a parameter planted in a
 * callback URL cannot override the payment data.
 */
final class CallbackData
{
    /** Parameters PayGate.to adds to the callback URL; never use these names for your own parameters. */
    public const PAYGATE_PARAMS = ['value_coin', 'coin', 'txid_in', 'txid_out', 'address_in', 'value_forwarded_coin', 'address_out', 'pending'];

    /** @var array<string,string> */
    private $params;

    /**
     * @param array<string,string> $params Decoded parameters.
     */
    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * Parses a raw query string. Unlike parse_str(), names keep their dots and spaces. "+" means
     * a space, and a repeated name keeps its last value.
     */
    public static function fromQueryString(string $rawQuery): self
    {
        $params = [];
        if (strncmp($rawQuery, '?', 1) === 0) {
            $rawQuery = substr($rawQuery, 1);
        }
        if ($rawQuery !== '') {
            foreach (explode('&', $rawQuery) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                $name = urldecode($eq === false ? $pair : substr($pair, 0, $eq));
                $value = $eq === false ? '' : urldecode(substr($pair, $eq + 1));
                $params[$name] = $value;
            }
        }

        return new self($params);
    }

    /**
     * Reads the current request's raw query string.
     *
     * @param array<string,mixed>|null $server Defaults to $_SERVER.
     */
    public static function fromRequest(?array $server = null): self
    {
        return self::fromQueryString(CallbackVerifier::rawQueryFrom($server ?? $_SERVER));
    }

    /** Any parameter, including the ones you put in the callback URL. */
    public function get(string $name, ?string $default = null): ?string
    {
        return array_key_exists($name, $this->params) ? $this->params[$name] : $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->params);
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->params;
    }

    /** Amount paid. Card: USDC/USDT after the provider's fee. Crypto: in the paid coin. */
    public function getValueCoin(): ?string
    {
        return $this->get('value_coin');
    }

    /** Coin of the payment: "polygon_usdc", "btc", "erc20_usdt"... */
    public function getCoin(): ?string
    {
        return $this->get('coin');
    }

    /**
     * Transaction that paid the order's address. A verified callback can arrive more than once:
     * key your processing on this (or your own order reference) so a payment is credited once.
     */
    public function getTxidIn(): ?string
    {
        return $this->get('txid_in');
    }

    /** Transaction that forwarded the funds to your wallet. */
    public function getTxidOut(): ?string
    {
        return $this->get('txid_out');
    }

    /** The order's temporary deposit address. */
    public function getAddressIn(): ?string
    {
        return $this->get('address_in');
    }

    /** Total forwarded to you (and any affiliates), after fees. */
    public function getValueForwardedCoin(): ?string
    {
        return $this->get('value_forwarded_coin');
    }

    /**
     * True for a crypto payment that has been seen but not yet confirmed (wallets created with
     * pending => true). Do not ship goods on a pending callback.
     */
    public function isPending(): bool
    {
        $pending = $this->get('pending');

        return $pending !== null && $pending !== '' && $pending !== '0' && strtolower($pending) !== 'false';
    }

    /**
     * Share of value_forwarded_coin each payout address received (decoded address_out).
     *
     * @return array<string,string> address => share as a plain decimal ("0.9250")
     */
    public function getAddressOut(): array
    {
        $json = $this->get('address_out');
        if ($json === null || $json === '') {
            return [];
        }
        // Quote bare numbers first so json_decode() never turns a share into a float.
        $quoted = preg_replace('/([:\[,]\s*)(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}\]])/', '$1"$2"', $json);
        $decoded = json_decode((string) $quoted, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('address_out is not a JSON object.');
        }
        $shares = [];
        foreach ($decoded as $address => $share) {
            $plain = is_string($share) ? Decimal::plain($share) : null;
            if ($plain === null) {
                throw new InvalidArgumentException('address_out has a non-numeric share for ' . $address . '.');
            }
            $shares[(string) $address] = $plain;
        }

        return $shares;
    }

    /**
     * Exact amount each payout address received: share × value_forwarded_coin, computed without
     * floats. Pass $scale to round half up to that many decimals.
     *
     * @return array<string,string> address => amount
     */
    public function getPayouts(?int $scale = null): array
    {
        $forwarded = $this->getValueForwardedCoin();
        if ($forwarded === null || Decimal::plain($forwarded) === null) {
            return [];
        }
        $payouts = [];
        foreach ($this->getAddressOut() as $address => $share) {
            $amount = Decimal::multiply($forwarded, $share);
            $payouts[$address] = $scale === null ? $amount : Decimal::round($amount, $scale);
        }

        return $payouts;
    }

    /** Amount received by one payout address (case-insensitive match), null if it was not paid. */
    public function getPayoutFor(string $address, ?int $scale = null): ?string
    {
        foreach ($this->getPayouts($scale) as $payee => $amount) {
            if (strcasecmp($payee, trim($address)) === 0) {
                return $amount;
            }
        }

        return null;
    }
}

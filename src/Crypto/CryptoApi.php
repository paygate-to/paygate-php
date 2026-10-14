<?php

declare(strict_types=1);

namespace PayGate\Crypto;

use PayGate\Exception\ApiException;
use PayGate\Exception\InvalidArgumentException;
use PayGate\Http\ApiRequester;
use PayGate\Support\Amount;
use PayGate\Support\Validate;

/**
 * Crypto payments: Bitcoin, Solana, Monero, Zcash, ETH, USDT, USDC and many more, forwarded
 * instantly to your own wallets.
 *
 * Two ways to take a payment:
 *  - On your site: createWallet() for the chosen coin, convert() the order total, show the
 *    address with qrCode().
 *  - Hosted page: createHostedWallet() with one wallet per network, then send the customer to
 *    hostedPaymentUrl(); they pick the coin there.
 *
 * Coins are written "btc", "erc20_usdc" or "erc20/usdc"; all forms are accepted.
 */
final class CryptoApi
{
    /** Networks accepted by createHostedWallet(). "evm" covers ERC20, BEP20, Polygon, Arbitrum, Base, Optimism, Avalanche-C... */
    public const HOSTED_NETWORKS = ['evm', 'btc', 'bitcoincash', 'ltc', 'doge', 'solana', 'trc20', 'xmr', 'zec', 'ton'];

    /** @var ApiRequester */
    private $api;

    /** @var string */
    private $checkoutHost;

    /**
     * @internal Use Client::crypto().
     */
    public function __construct(ApiRequester $api, string $checkoutHost)
    {
        $this->api = $api;
        $this->checkoutHost = $checkoutHost;
    }

    /**
     * Every supported coin with its logo and minimum (GET /crypto/info.php, or aff-info.php with $affiliate).
     *
     * @return array<string,Coin> keyed by coin id ("btc", "erc20_usdc"...)
     */
    public function coins(bool $affiliate = false): array
    {
        $data = $this->api->get($affiliate ? 'crypto/aff-info.php' : 'crypto/info.php');
        $coins = [];
        foreach ($data as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['ticker'])) {
                $coin = self::coinFrom((string) $entry['ticker'], '', $entry);
                $coins[$coin->getId()] = $coin;
                continue;
            }
            foreach ($entry as $child) {
                if (is_array($child) && isset($child['ticker'])) {
                    $coin = self::coinFrom((string) $child['ticker'], (string) $key, $child);
                    $coins[$coin->getId()] = $coin;
                }
            }
        }

        return $coins;
    }

    /**
     * Name, minimum and fiat prices of one coin (GET /crypto/{coin}/info.php, or aff-info.php).
     *
     * @return array{coin: string, minimum: string, prices: array<string,string>}
     */
    public function info(string $coin, bool $affiliate = false): array
    {
        $data = $this->api->get('crypto/' . self::coinPath($coin) . '/' . ($affiliate ? 'aff-info.php' : 'info.php'));

        return [
            'coin' => (string) ($data['coin'] ?? ''),
            'minimum' => isset($data['minimum']) ? self::number($data['minimum']) : '',
            'prices' => self::stringMap($data['prices'] ?? []),
        ];
    }

    /**
     * Price of one unit of the coin in a fiat currency, from info().
     */
    public function price(string $coin, string $fiatCurrency): string
    {
        $info = $this->info($coin);
        $currency = strtoupper(trim($fiatCurrency));
        if (!isset($info['prices'][$currency])) {
            throw new ApiException(sprintf('No %s price for %s.', $currency, self::coinId($coin)));
        }

        return $info['prices'][$currency];
    }

    /**
     * Estimated blockchain fee to forward a payment (GET /crypto/{coin}/fees.php, or aff-fees.php).
     *
     * @return array{coin: string, fiat: array<string,string>} fee in the network's coin and in fiat currencies
     */
    public function fees(string $coin, bool $affiliate = false): array
    {
        $data = self::success($this->api->get('crypto/' . self::coinPath($coin) . '/' . ($affiliate ? 'aff-fees.php' : 'fees.php')), 'fees');

        return [
            'coin' => isset($data['estimated_cost']) ? self::number($data['estimated_cost']) : '',
            'fiat' => self::stringMap($data['estimated_cost_currency'] ?? []),
        ];
    }

    /**
     * Convert a fiat amount to the coin, to show the customer what to send (GET /crypto/{coin}/convert.php).
     *
     * @param int|float|string $amount
     *
     * @return array{value: string, exchange_rate: string}
     */
    public function convert(string $coin, $amount, string $fiatCurrency): array
    {
        $data = $this->api->get('crypto/' . self::coinPath($coin) . '/convert.php', [
            'from' => strtolower(trim($fiatCurrency)),
            'value' => Amount::format($amount),
        ]);
        if (!isset($data['value_coin']) || !is_scalar($data['value_coin'])) {
            throw new ApiException(sprintf('PayGate.to could not convert %s to %s.', strtoupper($fiatCurrency), self::coinId($coin)), 200, (string) json_encode($data));
        }

        return ['value' => self::number($data['value_coin']), 'exchange_rate' => isset($data['exchange_rate']) ? self::number($data['exchange_rate']) : ''];
    }

    /**
     * Create the receiving address for one order (GET /crypto/{coin}/wallet.php).
     *
     * @param string $payoutWallet Your wallet for this coin's network
     * @param string $callbackUrl  Your callback URL with at least one parameter unique to the order
     * @param array{pending?: bool, confirmations?: int} $options
     *        pending: also notify unconfirmed transactions (callbacks then carry pending=1 first)
     *        confirmations: confirmations required before the confirmed callback (default 1)
     */
    public function createWallet(string $coin, string $payoutWallet, string $callbackUrl, array $options = []): CryptoWallet
    {
        $query = [
            'address' => Validate::wallet($payoutWallet, 'Payout wallet'),
            'callback' => Validate::callback($callbackUrl),
        ];

        return $this->requestWallet($coin, 'wallet.php', $query + self::walletOptions($options));
    }

    /**
     * White-label: split each payment with an affiliate wallet.
     * Without fees the affiliate earns the default commission (affiliate.php). With fees,
     * affiliateFee + merchantFee must equal 0.99 (custom-affiliate.php).
     *
     * @param int|float|string|null                     $affiliateFee
     * @param int|float|string|null                     $merchantFee
     * @param array{pending?: bool, confirmations?: int} $options
     */
    public function createAffiliateWallet(string $coin, string $payoutWallet, string $callbackUrl, string $affiliateWallet, $affiliateFee = null, $merchantFee = null, array $options = []): CryptoWallet
    {
        $query = [
            'address' => Validate::wallet($payoutWallet, 'Payout wallet'),
            'callback' => Validate::callback($callbackUrl),
            'affiliate' => Validate::wallet($affiliateWallet, 'Affiliate wallet'),
        ];
        if ($affiliateFee === null && $merchantFee === null) {
            return $this->requestWallet($coin, 'affiliate.php', $query + self::walletOptions($options));
        }
        $query['affiliate_fee'] = Validate::share($affiliateFee, 'affiliateFee');
        $query['merchant_fee'] = Validate::share($merchantFee, 'merchantFee');
        Validate::assertShares([$query['affiliate_fee'], $query['merchant_fee']]);

        return $this->requestWallet($coin, 'custom-affiliate.php', $query + self::walletOptions($options));
    }

    /**
     * Split each payment across up to 19 wallets (POST /crypto/{coin}/mass-payout.php).
     *
     * @param array<string,int|float|string>             $shares wallet => share, must total 0.99
     * @param array{pending?: bool, confirmations?: int} $options
     */
    public function createMassPayoutWallet(string $coin, string $callbackUrl, array $shares, array $options = []): CryptoWallet
    {
        $path = 'crypto/' . self::coinPath($coin) . '/mass-payout.php';
        $data = $this->api->postJson($path, ['callback' => Validate::callback($callbackUrl), 'fees' => Validate::shares($shares)], self::walletOptions($options));

        return self::toWallet($coin, $data, $path);
    }

    /**
     * Blockchain fee and minimum for a mass payout to $walletCount wallets.
     *
     * @return array{fee_coin: string, fee_fiat: array<string,string>}
     */
    public function massPayoutFees(string $coin, int $walletCount): array
    {
        if ($walletCount < 1 || $walletCount > 19) {
            throw new InvalidArgumentException('walletCount must be between 1 and 19.');
        }
        $data = self::success($this->api->get('crypto/' . self::coinPath($coin) . '/mass-payout-fees.php', ['wallet_count' => $walletCount]), 'mass payout fees');

        return [
            'fee_coin' => isset($data['estimated_cost']) ? self::number($data['estimated_cost']) : '',
            'fee_fiat' => self::stringMap($data['estimated_cost_currency'] ?? []),
        ];
    }

    /**
     * QR code for a receiving address (GET /crypto/{coin}/qrcode.php).
     *
     * @param string|null $amount Optional amount to embed. Not every wallet app reads it.
     *
     * @return array{png_base64: string, data_uri: string, payment_uri: string}
     */
    public function qrCode(string $coin, string $address, ?string $amount = null): array
    {
        $query = ['address' => Validate::wallet($address, 'Address')];
        if ($amount !== null && $amount !== '') {
            $query['amount'] = Amount::format($amount);
        }
        $data = $this->api->get('crypto/' . self::coinPath($coin) . '/qrcode.php', $query);
        if (!isset($data['qr_code']) || !is_string($data['qr_code']) || $data['qr_code'] === '') {
            throw new ApiException('PayGate.to did not return a QR code.', 200, (string) json_encode($data));
        }

        return [
            'png_base64' => $data['qr_code'],
            'data_uri' => 'data:image/png;base64,' . $data['qr_code'],
            'payment_uri' => (string) ($data['payment_uri'] ?? $address),
        ];
    }

    /**
     * Prepare the hosted multi-coin page for one order (POST /crypto/multi-hosted-wallet.php).
     *
     * @param array<string,string> $wallets      network => your wallet; networks: see HOSTED_NETWORKS.
     *                                           Networks you leave out are not offered.
     * @param int|float|string     $fiatAmount   Order total
     * @param string               $fiatCurrency e.g. USD or EUR
     */
    public function createHostedWallet(array $wallets, $fiatAmount, string $fiatCurrency, string $callbackUrl): HostedWallet
    {
        $payload = [];
        foreach ($wallets as $network => $address) {
            $network = strtolower(trim((string) $network));
            if (!in_array($network, self::HOSTED_NETWORKS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown network "%s". Use: %s.', $network, implode(', ', self::HOSTED_NETWORKS)));
            }
            if (trim((string) $address) === '') {
                continue;
            }
            $payload[$network] = Validate::wallet((string) $address, ucfirst($network) . ' wallet');
        }
        if ($payload === []) {
            throw new InvalidArgumentException('Give at least one network wallet.');
        }
        $payload['fiat_amount'] = (float) Amount::format($fiatAmount, 'Fiat amount');
        $payload['fiat_currency'] = strtoupper(trim($fiatCurrency));
        $payload['callback'] = Validate::callback($callbackUrl);

        $data = $this->api->postJson('crypto/multi-hosted-wallet.php', $payload);
        if (!isset($data['payment_token']) || !is_string($data['payment_token']) || $data['payment_token'] === '') {
            throw new ApiException('PayGate.to did not return a payment_token. Check the wallets.', 200, (string) json_encode($data));
        }

        return new HostedWallet($data['payment_token'], (string) ($data['callback_url'] ?? ''), (string) ($data['ipn_token'] ?? ''), $data);
    }

    /**
     * Link to the hosted multi-coin page.
     *
     * @param HostedWallet|string $wallet  From createHostedWallet(), or its payment_token
     * @param bool                $addFees Add the estimated blockchain fee to what the customer pays
     * @param array{domain?: string, logo?: string, background?: string, theme?: string, button?: string} $branding
     */
    public function hostedPaymentUrl($wallet, bool $addFees = false, array $branding = []): string
    {
        $token = $wallet instanceof HostedWallet ? $wallet->getPaymentToken() : (string) $wallet;
        if (trim($token) === '') {
            throw new InvalidArgumentException('payment_token is empty: create the hosted wallet first.');
        }
        $query = ['payment_token' => $token, 'add_fees' => $addFees ? '1' : '0'];
        foreach (Validate::branding($branding) as $name => $value) {
            $query[$name] = $value;
        }

        return 'https://' . $this->checkoutHost . '/crypto/hosted.php' . ApiRequester::queryString($query, ['payment_token']);
    }

    /**
     * Payment status for occasional checks (GET /crypto/payment-status.php). Rate limited:
     * update orders from the signed callback, not from this.
     *
     * @return array{status: string, paid: bool, value_coin: string, txid_in: string, txid_out: string, coin: string}
     */
    public function paymentStatus(string $ipnToken, string $coin): array
    {
        if (trim($ipnToken) === '') {
            throw new InvalidArgumentException('ipn_token is empty.');
        }
        $data = $this->api->get('crypto/payment-status.php', ['ipn_token' => trim($ipnToken), 'ticker' => self::coinPath($coin)], ['ipn_token']);
        $status = (string) ($data['status'] ?? '');

        return [
            'status' => $status,
            'paid' => strtolower($status) === 'paid',
            'value_coin' => isset($data['value_coin']) ? self::number($data['value_coin']) : '',
            'txid_in' => (string) ($data['txid_in'] ?? ''),
            'txid_out' => (string) ($data['txid_out'] ?? ''),
            'coin' => (string) ($data['coin'] ?? ''),
        ];
    }

    /**
     * Coins that can be swapped (GET /crypto/swap-info.php).
     *
     * @return array<int,string> paths such as "trc20/usdt"
     */
    public function swapCoins(): array
    {
        $data = $this->api->get('crypto/swap-info.php');

        return isset($data['tickers']) && is_array($data['tickers']) ? array_values(array_map('strval', $data['tickers'])) : [];
    }

    /**
     * Create a swap: the customer sends $amount of $fromCoin and $toWallet receives $toCoin
     * (GET /crypto/{from}/swap.php, or aff-swap.php with an affiliate).
     *
     * @param int|float|string      $amount
     * @param int|float|string|null $affiliateFee e.g. 0.05 for 5%, with $affiliateWallet
     *
     * @return array<mixed> the API response: data.id, data.exchange_address_wallet, data.amount_to...
     */
    public function createSwap(string $fromCoin, string $toCoin, string $toWallet, $amount, string $callbackUrl, ?string $affiliateWallet = null, $affiliateFee = null): array
    {
        $query = [
            'address' => Validate::wallet($toWallet, 'Receiving wallet'),
            'callback' => Validate::callback($callbackUrl),
            'amount' => Amount::format($amount),
            'ticker_to' => self::coinPath($toCoin),
        ];
        $endpoint = 'swap.php';
        if ($affiliateWallet !== null && $affiliateWallet !== '') {
            $query['affiliate_fee'] = Validate::share($affiliateFee, 'affiliateFee');
            $query['affiliate'] = Validate::wallet($affiliateWallet, 'Affiliate wallet');
            $endpoint = 'aff-swap.php';
        }

        // Not retried: every call creates a new exchange.
        return $this->api->get('crypto/' . self::coinPath($fromCoin) . '/' . $endpoint, $query, [], false);
    }

    /**
     * Status of a swap (GET /crypto/control/swap-status.php). For occasional checks.
     *
     * @return array<mixed>
     */
    public function swapStatus(string $exchangeId): array
    {
        if (trim($exchangeId) === '') {
            throw new InvalidArgumentException('exchange_id is empty.');
        }

        return $this->api->get('crypto/control/swap-status.php', ['exchange_id' => trim($exchangeId)]);
    }

    /**
     * @param array<string,string> $query
     */
    private function requestWallet(string $coin, string $endpoint, array $query): CryptoWallet
    {
        $path = 'crypto/' . self::coinPath($coin) . '/' . $endpoint;

        return self::toWallet($coin, $this->api->get($path, $query), $path);
    }

    /**
     * @param array<mixed> $data
     */
    private static function toWallet(string $coin, array $data, string $path): CryptoWallet
    {
        if (!isset($data['address_in']) || !is_string($data['address_in']) || $data['address_in'] === '') {
            throw new ApiException('PayGate.to did not return an address_in for ' . $path . '. Check the payout wallet matches the coin.', 200, (string) json_encode($data));
        }

        return new CryptoWallet(self::coinId($coin), $data['address_in'], (string) ($data['callback_url'] ?? ''), (string) ($data['ipn_token'] ?? ''), $data);
    }

    /**
     * Endpoints documented with "status": "success": any other status is a failure.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function success(array $data, string $what): array
    {
        $status = isset($data['status']) && is_scalar($data['status']) ? strtolower((string) $data['status']) : 'success';
        if ($status !== 'success' || !isset($data['estimated_cost'])) {
            throw new ApiException(sprintf('PayGate.to could not estimate the %s.', $what), 200, (string) json_encode($data));
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $options
     *
     * @return array<string,string>
     */
    private static function walletOptions(array $options): array
    {
        $unknown = array_diff(array_keys($options), ['pending', 'confirmations']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown wallet option(s): ' . implode(', ', $unknown) . '. Use pending, confirmations.');
        }
        $out = [];
        if (!empty($options['pending'])) {
            $out['pending'] = '1';
        }
        if (isset($options['confirmations'])) {
            $count = (int) $options['confirmations'];
            if ($count < 1) {
                throw new InvalidArgumentException('confirmations must be at least 1.');
            }
            $out['confirmations'] = (string) $count;
        }

        return $out;
    }

    private static function coinPath(string $coin): string
    {
        $path = Coin::path($coin);
        if ($path === '' || !preg_match('#^[a-z0-9][a-z0-9.-]*(/[a-z0-9][a-z0-9._-]*)?$#', $path)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a coin identifier such as btc or erc20_usdc.', $coin));
        }

        return $path;
    }

    private static function coinId(string $coin): string
    {
        return Coin::id($coin);
    }

    /**
     * @param array<mixed> $entry
     */
    private static function coinFrom(string $ticker, string $chain, array $entry): Coin
    {
        $ticker = strtolower($ticker);
        $id = $chain === '' ? $ticker : strtolower($chain) . '_' . $ticker;
        $minimum = $entry['minimum_transaction_coin'] ?? ($entry['minimum'] ?? '');

        return new Coin(
            $id,
            $ticker,
            strtolower($chain),
            (string) ($entry['coin'] ?? strtoupper($ticker)),
            (string) ($entry['logo'] ?? ''),
            $minimum === '' ? '' : self::number($minimum)
        );
    }

    /**
     * Numbers from JSON as plain decimal strings (0.00010640000000000001 stays exact, 1.0E-5 becomes 0.00001).
     *
     * @param mixed $value
     */
    private static function number($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $text = (string) $value;
            if (stripos($text, 'e') !== false) {
                $text = rtrim(rtrim(sprintf('%.18F', $value), '0'), '.');
            }

            return $text;
        }

        return '';
    }

    /**
     * @param mixed $map
     *
     * @return array<string,string>
     */
    private static function stringMap($map): array
    {
        $out = [];
        if (is_array($map)) {
            foreach ($map as $key => $value) {
                if (is_scalar($value)) {
                    $out[strtoupper((string) $key)] = self::number($value);
                }
            }
        }

        return $out;
    }
}

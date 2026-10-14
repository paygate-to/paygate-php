<?php

declare(strict_types=1);

namespace PayGate\Card;

use PayGate\Exception\ApiException;
use PayGate\Exception\InvalidArgumentException;
use PayGate\Http\ApiRequester;
use PayGate\Support\Amount;
use PayGate\Support\Validate;

/**
 * Card payments (Visa, Mastercard, Apple Pay, Google Pay, bank transfers...) paid out instantly
 * to your USDC (Polygon) wallet.
 *
 * Flow: createWallet() for the order, then send the customer to paymentUrl().
 * PayGate.to calls your callback URL when the payment is complete.
 */
final class CardApi
{
    /** @var ApiRequester */
    private $api;

    /** @var string */
    private $checkoutHost;

    /**
     * @internal Use Client::card().
     */
    public function __construct(ApiRequester $api, string $checkoutHost)
    {
        $this->api = $api;
        $this->checkoutHost = $checkoutHost;
    }

    /**
     * Create the wallet for one order (GET /control/wallet.php).
     *
     * @param string $payoutWallet Your USDC (Polygon) wallet, 0x...
     * @param string $callbackUrl  Your callback URL with at least one parameter unique to the order.
     *                             The same callback always returns the same wallet.
     */
    public function createWallet(string $payoutWallet, string $callbackUrl): CardWallet
    {
        return $this->requestWallet('control/wallet.php', [
            'address' => Validate::wallet($payoutWallet, 'Payout wallet'),
            'callback' => Validate::callback($callbackUrl),
        ]);
    }

    /**
     * White-label: split each payout with an affiliate wallet.
     * Without fees, the affiliate earns the default commission (GET /control/affiliate.php).
     * With fees, affiliateFee + merchantFee must equal 0.99 (GET /control/custom-affiliate.php).
     *
     * @param int|float|string|null $affiliateFee e.g. 0.01 for 1%
     * @param int|float|string|null $merchantFee  e.g. 0.98 for 98%
     */
    public function createAffiliateWallet(string $payoutWallet, string $callbackUrl, string $affiliateWallet, $affiliateFee = null, $merchantFee = null): CardWallet
    {
        $query = [
            'address' => Validate::wallet($payoutWallet, 'Payout wallet'),
            'callback' => Validate::callback($callbackUrl),
            'affiliate' => Validate::wallet($affiliateWallet, 'Affiliate wallet'),
        ];
        if ($affiliateFee === null && $merchantFee === null) {
            return $this->requestWallet('control/affiliate.php', $query);
        }
        $query['affiliate_fee'] = Validate::share($affiliateFee, 'affiliateFee');
        $query['merchant_fee'] = Validate::share($merchantFee, 'merchantFee');
        Validate::assertShares([$query['affiliate_fee'], $query['merchant_fee']]);

        return $this->requestWallet('control/custom-affiliate.php', $query);
    }

    /**
     * White-label with a sub-affiliate (GET /control/custom-sub-affiliate.php).
     * affiliateFee + subAffiliateFee + merchantFee must equal 0.99.
     *
     * @param int|float|string $affiliateFee
     * @param int|float|string $subAffiliateFee
     * @param int|float|string $merchantFee
     */
    public function createSubAffiliateWallet(
        string $payoutWallet,
        string $callbackUrl,
        string $affiliateWallet,
        string $subAffiliateWallet,
        $affiliateFee,
        $subAffiliateFee,
        $merchantFee
    ): CardWallet {
        $query = [
            'address' => Validate::wallet($payoutWallet, 'Payout wallet'),
            'callback' => Validate::callback($callbackUrl),
            'affiliate' => Validate::wallet($affiliateWallet, 'Affiliate wallet'),
            'affiliate_fee' => Validate::share($affiliateFee, 'affiliateFee'),
            'merchant_fee' => Validate::share($merchantFee, 'merchantFee'),
            'sub_affiliate_fee' => Validate::share($subAffiliateFee, 'subAffiliateFee'),
            'sub_affiliate' => Validate::wallet($subAffiliateWallet, 'Sub-affiliate wallet'),
        ];
        Validate::assertShares([$query['affiliate_fee'], $query['merchant_fee'], $query['sub_affiliate_fee']]);

        return $this->requestWallet('control/custom-sub-affiliate.php', $query);
    }

    /**
     * Split each payout across up to 19 wallets (POST /control/mass-payout.php).
     *
     * @param array<string,int|float|string> $shares wallet => share, e.g. ['0xA...' => 0.58, '0xB...' => 0.41]; must total 0.99
     */
    public function createMassPayoutWallet(string $callbackUrl, array $shares): CardWallet
    {
        $fees = Validate::shares($shares);
        $data = $this->api->postJson('control/mass-payout.php', ['callback' => Validate::callback($callbackUrl), 'fees' => $fees]);

        return self::toWallet($data, 'control/mass-payout.php');
    }

    /**
     * Link that sends the customer to pay.
     *
     * Without a provider: the hosted multi-provider page (pay.php), which shows the providers that work
     * for the customer's country, amount and currency. With a provider id (see providers()): that
     * provider directly (process-payment.php).
     *
     * @param CardWallet|string $wallet   The wallet from createWallet(), or its address_in
     * @param int|float|string  $amount   Order total, e.g. 49.90
     * @param string            $currency ISO code, e.g. USD or EUR
     * @param array{domain?: string, logo?: string, background?: string, theme?: string, button?: string} $branding
     *        Hosted page only: white-label domain, logo URL and colours (hex or CSS names).
     */
    public function paymentUrl($wallet, $amount, string $email, string $currency, ?string $provider = null, array $branding = []): string
    {
        $addressIn = $wallet instanceof CardWallet ? $wallet->getAddressIn() : (string) $wallet;
        if (trim($addressIn) === '') {
            throw new InvalidArgumentException('address_in is empty: create the wallet first.');
        }
        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            throw new InvalidArgumentException('A valid customer email address is required.');
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(sprintf('Currency "%s" must be a three-letter ISO code such as USD.', $currency));
        }

        $query = [
            'address' => $addressIn,
            'amount' => Amount::format($amount),
            'email' => $email,
            'currency' => $currency,
        ];

        if ($provider !== null && $provider !== '') {
            if ($branding !== []) {
                throw new InvalidArgumentException('Branding options only apply to the hosted multi-provider page (no provider).');
            }
            $query = ['address' => $query['address'], 'amount' => $query['amount'], 'provider' => trim($provider), 'email' => $email, 'currency' => $currency];

            return 'https://' . $this->checkoutHost . '/process-payment.php' . ApiRequester::queryString($query, ['address']);
        }

        foreach (Validate::branding($branding) as $name => $value) {
            $query[$name] = $value;
        }

        return 'https://' . $this->checkoutHost . '/pay.php' . ApiRequester::queryString($query, ['address']);
    }

    /**
     * All card providers with their status and minimum order (GET /control/provider-status).
     *
     * @return array<int,Provider>
     */
    public function providers(): array
    {
        $data = $this->api->get('control/provider-status');
        $list = isset($data['providers']) && is_array($data['providers']) ? $data['providers'] : [];
        $out = [];
        foreach ($list as $row) {
            if (is_array($row) && isset($row['id'])) {
                $out[] = Provider::fromArray($row);
            }
        }

        return $out;
    }

    /**
     * Convert an amount to USD (GET /control/convert.php). Some providers accept USD only.
     *
     * @param int|float|string $amount
     *
     * @return array{value: string, exchange_rate: string}
     */
    public function convertToUsd($amount, string $fromCurrency): array
    {
        $from = strtoupper(trim($fromCurrency));
        $value = Amount::format($amount);
        if ($from === 'USD') {
            return ['value' => $value, 'exchange_rate' => '1'];
        }
        $data = $this->api->get('control/convert.php', ['from' => $from, 'value' => $value]);
        if (!isset($data['value_coin']) || !is_scalar($data['value_coin'])) {
            throw new ApiException('PayGate.to could not convert ' . $from . ' to USD.', 200, (string) json_encode($data));
        }

        return ['value' => (string) $data['value_coin'], 'exchange_rate' => (string) ($data['exchange_rate'] ?? '')];
    }

    /**
     * Payment status for occasional checks (GET /control/payment-status.php). Rate limited:
     * update orders from the signed callback, not from this.
     *
     * @return array{status: string, paid: bool, value_coin: string, txid_out: string, coin: string}
     */
    public function paymentStatus(string $ipnToken): array
    {
        if (trim($ipnToken) === '') {
            throw new InvalidArgumentException('ipn_token is empty.');
        }
        $data = $this->api->get('control/payment-status.php', ['ipn_token' => trim($ipnToken)], ['ipn_token']);
        $status = (string) ($data['status'] ?? '');

        return [
            'status' => $status,
            'paid' => strtolower($status) === 'paid',
            'value_coin' => (string) ($data['value_coin'] ?? ''),
            'txid_out' => (string) ($data['txid_out'] ?? ''),
            'coin' => (string) ($data['coin'] ?? ''),
        ];
    }

    /**
     * @param array<string,string> $query
     */
    private function requestWallet(string $path, array $query): CardWallet
    {
        return self::toWallet($this->api->get($path, $query), $path);
    }

    /**
     * @param array<mixed> $data
     */
    private static function toWallet(array $data, string $path): CardWallet
    {
        if (!isset($data['address_in']) || !is_string($data['address_in']) || $data['address_in'] === '') {
            throw new ApiException('PayGate.to did not return an address_in for ' . $path . '. Check the payout wallet.', 200, (string) json_encode($data));
        }

        return CardWallet::fromArray($data);
    }
}

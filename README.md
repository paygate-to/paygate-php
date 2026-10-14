# PayGate.to PHP SDK

Accept card and crypto payments in PHP without KYC, with every payment forwarded instantly to your own wallet.

- **Cards**: Visa, Mastercard, Apple Pay, Google Pay and more through the hosted checkout. You receive USDC on Polygon.
- **Crypto**: Bitcoin, Ethereum, USDT, USDC, Solana, Litecoin and many more on your own page with a QR code, or on the hosted multi-coin page, which also takes Monero, Zcash and TON.
- **Callbacks**: PayGate.to's RSA signature is checked against the bundled public key. Payout amounts are decoded for you.
- **White label**: you can use your own checkout domain, logo and colours, earn affiliate commissions, and split payouts across up to 19 wallets.

PHP 7.4 or newer. The SDK needs `ext-json` and `ext-openssl`. `ext-curl` is optional; the SDK uses it when it is installed. It has no other dependencies.

Full guide: <https://paygate.to/docs/sdk/php/>

## Contents

- [Install](#install)
- [Card payments](#card-payments)
- [Crypto payments on your page](#crypto-payments-on-your-page)
- [Hosted multi-coin page](#hosted-multi-coin-page)
- [Handling the callback](#handling-the-callback)
- [White label, affiliates and split payouts](#white-label-affiliates-and-split-payouts)
- [Coin data, conversion and status](#coin-data-conversion-and-status)
- [Configuration](#configuration)
- [Errors](#errors)
- [Examples](#examples)
- [Testing](#testing)
- [Security notes](#security-notes)
- [API reference](#api-reference)

## Install

```bash
composer require paygate-to/paygate-php
```

```php
require 'vendor/autoload.php';

$paygate = new PayGate\Client();
```

## Card payments

There are three steps for each order:
1. Create a wallet tied to the order's callback URL.
2. Send the customer to the payment link.
3. Credit the order when the signed callback arrives.

```php
use PayGate\Callback\CallbackToken;

$tokens = new CallbackToken(getenv('PAYGATE_CALLBACK_SECRET'));   // 64 random hex chars, kept on your server

// Your callback URL. It carries the order id and a token so the id cannot be swapped.
$callback = $tokens->callbackUrl('https://shop.example/paygate/callback.php', ['order' => $order->id]);

$wallet = $paygate->card()->createWallet('0xYourPolygonUsdcWallet', $callback);

// What you expect to receive, in USD: the callback compares the amount that arrived with this.
$order->expectedUsd = $paygate->card()->convertToUsd($order->total, 'EUR')['value'];

$payUrl = $paygate->card()->paymentUrl($wallet, $order->total, $order->email, 'EUR');
header('Location: ' . $payUrl);
```

The customer can see the amount in the payment link, so always compare what arrives with what you expect (see [Handling the callback](#handling-the-callback)).

`paymentUrl()` opens the hosted page, which lists the providers available for the customer's country, amount and currency. You can send the customer straight to one provider instead. `providers()` lists the providers, with their status and minimum order:

```php
foreach ($paygate->card()->providers() as $provider) {
    if ($provider->isActive()) {
        echo $provider->getId(), ' ', $provider->getName(), ' min ', $provider->getMinimumAmount(), ' ', $provider->getMinimumCurrency(), "\n";
    }
}

$payUrl = $paygate->card()->paymentUrl($wallet, '49.90', 'buyer@example.com', 'USD', 'wert');
```

The same callback URL always returns the same wallet, so rebuilding the link for an order is safe. Store `$wallet->getIpnToken()` if you want to use `paymentStatus()` later.

## Crypto payments on your page

```php
$crypto = $paygate->crypto();

$wallet = $crypto->createWallet('btc', 'bc1qYourBitcoinWallet', $callback);
$quote  = $crypto->convert('btc', $order->total, 'USD');              // ['value' => '0.00041512', 'exchange_rate' => ...]
$qr     = $crypto->qrCode('btc', $wallet->getAddressIn(), $quote['value']);

echo 'Send ', $quote['value'], ' BTC to ', $wallet->getAddressIn();
echo '<img src="', $qr['data_uri'], '" alt="Payment QR code">';
```

Coins are written as `btc`, `erc20_usdc` or `erc20/usdc`; all three forms work. Use a payout wallet on the coin's network, for example an EVM address for ERC20, BEP20 and Polygon tokens.

Wallet options:

```php
$crypto->createWallet('ltc', $myLtcWallet, $callback, [
    'pending' => true,        // also send a callback (pending=1) when the transaction is first seen
    'confirmations' => 3,     // confirmations required before the confirmed callback
]);
```

## Hosted multi-coin page

The customer picks a coin on the hosted page. Pass one wallet for each network you accept (`CryptoApi::HOSTED_NETWORKS`: `evm`, `btc`, `bitcoincash`, `ltc`, `doge`, `solana`, `trc20`, `xmr`, `zec`, `ton`).

```php
$hosted = $paygate->crypto()->createHostedWallet([
    'btc' => 'bc1qYourBitcoinWallet',
    'evm' => '0xYourEvmWallet',            // ETH, USDT, USDC… on ERC20, BEP20, Polygon, Arbitrum, Base…
    'solana' => 'YourSolanaWallet',
], $order->total, 'USD', $callback);

$url = $paygate->crypto()->hostedPaymentUrl($hosted, true /* customer pays the network fee */, [
    'theme' => '#7c3aed',                  // hex codes or CSS colour names
]);
header('Location: ' . $url);
```

## Handling the callback

When a payment arrives, PayGate.to requests your callback URL with your parameters plus the payment data. The request is signed. Check the signature first, and reject the request if the check fails:

```php
use PayGate\Callback\CallbackData;
use PayGate\Crypto\Coin;

$result = $paygate->callbacks()->verifyRequest('https://shop.example/paygate/callback.php');
if (!$result->isValid()) {
    http_response_code(403);                 // $result->getReason() says why, e.g. signature_mismatch
    exit;
}

$data = CallbackData::fromRequest();

if (!$tokens->verify($data->get('order'), $data->get('token'))) {
    http_response_code(403);
    exit;
}

if ($data->isPending()) {                    // crypto wallets created with 'pending' => true
    exit('ok');                              // seen, not yet confirmed: do not ship
}

$order = findOrder($data->get('order'));
if ($order->hasTransaction($data->getTxidIn())) {
    exit('ok');                              // callbacks can repeat: credit each payment once
}

$order->addPayment($data->getTxidIn(), $data->getCoin(), $data->getValueCoin());

// Release the goods only when the right coin and enough of it arrived. Card payments arrive in
// USDC after the provider's fee: the official plugins accept 60% of the USD total or more.
if ($order->method === 'card') {
    $paid = (float) $order->receivedTotal() >= 0.60 * (float) $order->expectedUsd;
} else {
    $paid = Coin::id($data->getCoin()) === Coin::id($order->coin) && (float) $order->receivedTotal() >= 0.98 * (float) $order->expectedCoin;
}
$order->setStatus($paid ? 'paid' : 'underpaid');
echo 'ok';
```

Each check matters:

- **Signature**: proves PayGate.to sent the request and that nothing in it was changed.
- **Token**: the callback URL is chosen when the wallet is created, so anyone can create a wallet whose callback names one of your orders and pay it a few cents. The token proves your server issued that order reference. Without tokens, compare `getAddressIn()` with the order's deposit address (`getPolygonAddressIn()` for cards, `getAddressIn()` for crypto); the hosted page has no address in advance, so use tokens there.
- **Transaction id**: callbacks are repeated until you answer `ok`.
- **Coin and amount**: the payment link is visible to the customer, and any token can be sent to a crypto address.
- **Payout address**: callback URLs end up in web server logs. With a leaked URL, someone could create a wallet that pays *them* and still call your callback. `$data->getPayoutFor('0xYourWallet')` returns what reached your wallet (null if nothing did). Expect it to be at least 90% of the amount forwarded.

Never use PayGate.to's parameter names (`value_coin`, `coin`, `txid_in`, `txid_out`, `address_in`, `value_forwarded_coin`, `address_out`, `pending`) in your callback URL; the SDK refuses them. If a name appears twice, `CallbackData` uses the last value, which is PayGate.to's.

Pass `verifyRequest()` the callback URL you registered, as a constant in your code. Only its scheme, host and path are used. The signed string is that URL followed by the query string exactly as it arrived. The SDK reads the raw query from `REQUEST_URI` and never rebuilds it, because one changed byte invalidates the signature. If your framework gives you a request object, call `verify()` instead:

```php
$result = $paygate->callbacks()->verify(
    'https://shop.example/paygate/callback.php',
    $request->server->get('QUERY_STRING'),            // raw, undecoded
    $request->headers->get('X-PayGate-Signature'),
    $request->headers->get('X-PayGate-Key-Id')
);
```

> Don't use `$request->getQueryString()` in Symfony or Laravel: it sorts and re-encodes the query, which breaks the signature.

### Payment data

| Method | Value |
|---|---|
| `getValueCoin()` | Amount received. Card payments arrive in USDC (or USDT) on Polygon, after the provider's fee. Crypto payments are in the coin that was paid. |
| `getCoin()` | `polygon_usdc`, `btc`, `erc20_usdt`… |
| `getTxidIn()` | Transaction that paid the order's address. Use it to credit each payment once. |
| `getTxidOut()` | Transaction that forwarded the funds to you. |
| `getAddressIn()` | The order's deposit address. |
| `getValueForwardedCoin()` | Total forwarded to you and to any affiliates. |
| `getAddressOut()` | Share received by each payout address, e.g. `['0xYou' => '0.925']`. |
| `getPayouts($scale = null)` | Exact amount sent to each address (share × forwarded), computed without floats. Pass a scale to round half up. |
| `isPending()` | True for an unconfirmed crypto notice. |
| `get('order')` | Any of your own parameters. |

### Key rotation

The published key is bundled under the id `v1`. If PayGate.to introduces a new key, you can supply it without upgrading the SDK:

```php
$verifier = new PayGate\Callback\CallbackVerifier(null, function (string $keyId): ?string {
    return @file_get_contents(__DIR__ . "/keys/paygate_callback_public_{$keyId}.pem") ?: null;
});
```

An id the resolver doesn't know is asked again after 10 minutes, so a forged key id can't block a real key published later.

## White label, affiliates and split payouts

```php
// Use your own checkout domain and branding (hosted pages)
$paygate = new PayGate\Client([
    'api_domain' => 'api-yourshop-1a2b3c.paygate.to',   // your free dedicated endpoint
    'checkout_domain' => 'pay.yourshop.com',
]);
$paygate->card()->paymentUrl($wallet, '25', $email, 'USD', null, [
    'logo' => 'https://yourshop.com/logo.png',
    'background' => '#ffffff',
    'theme' => '#7c3aed',
    'button' => '#111827',
]);

// Earn a commission on a merchant's sales: shares must add up to 0.99 (PayGate.to keeps 1%)
$paygate->card()->createAffiliateWallet($merchantWallet, $callback, $yourWallet, 0.02, 0.97);
$paygate->crypto()->createAffiliateWallet('trc20_usdt', $merchantWallet, $callback, $yourWallet, 0.02, 0.97);

// Split each payment across up to 19 wallets
$paygate->card()->createMassPayoutWallet($callback, [
    '0xPartnerA' => 0.50,
    '0xPartnerB' => 0.49,
]);
```

Without fees, `createAffiliateWallet()` gives the affiliate the default commission. `createSubAffiliateWallet()` adds a third wallet.

## Coin data, conversion and status

```php
$crypto = $paygate->crypto();

$crypto->coins();                       // ['btc' => Coin, 'erc20_usdc' => Coin, …] with name, logo, minimum
$crypto->info('erc20_usdc');            // ['coin' => 'USD Coin', 'minimum' => '…', 'prices' => ['USD' => '1.00', …]]
$crypto->price('btc', 'EUR');           // '51756.00'
$crypto->fees('btc');                   // estimated network fee, in BTC and in fiat
$crypto->convert('sol_sol', '20', 'GBP');     // ['value' => '0.14', 'exchange_rate' => …]

$paygate->card()->convertToUsd('49.90', 'EUR');

// Occasional checks only. These endpoints are rate limited, so update orders from the callback.
$paygate->card()->paymentStatus($ipnToken);                // ['status' => 'paid', 'paid' => true, …]
$paygate->crypto()->paymentStatus($ipnToken, 'bep20_usdt');
```

Numbers are always returned as plain decimal strings (`0.00001`, never `1.0E-5`), so they keep their full precision.

## Configuration

```php
new PayGate\Client([
    'api_domain'      => 'api.paygate.to',       // or your dedicated endpoint
    'checkout_domain' => 'checkout.paygate.to',  // or your white-label domain
    'timeout'         => 30,                     // seconds
    'connect_timeout' => 10,
    'retries'         => 1,                      // extra attempts for GET on network errors; POST and createSwap() are never retried
    'user_agent'      => 'my-shop/2.0',
    'http_client'     => $myClient,              // implements PayGate\Http\HttpClientInterface
]);
```

Unknown options throw an exception, so a misspelled option fails loudly instead of being ignored. TLS is always verified and redirects are not followed.

## Errors

Every SDK exception extends `PayGate\Exception\PayGateException`:

| Exception | When |
|---|---|
| `InvalidArgumentException` | Your input was rejected before any request. Examples: a callback without an order parameter, fee shares that don't add up to 0.99, or a bad currency code. |
| `ApiException` | PayGate.to answered with an error, a non-2xx status or a body that isn't JSON. Use `getStatusCode()` and `getResponseBody()`. |
| `TransportException` | The request never completed (DNS, TLS, timeout). |

```php
try {
    $wallet = $paygate->card()->createWallet($payoutWallet, $callback);
} catch (PayGate\Exception\PayGateException $e) {
    error_log($e->getMessage());       // query strings are never included in messages
}
```

## Examples

The `examples/` folder has a small working shop. All its settings come from environment variables:

| File | What it does |
|---|---|
| `card-checkout.php` | Creates a card order and redirects to the hosted page. |
| `crypto-checkout.php` | Shows the address, amount and QR code for a coin. |
| `hosted-checkout.php` | Redirects to the multi-coin page. |
| `callback.php` | Verifies the signature and the token, handles repeats and pending notices, and checks the coin and the amount. |
| `list-coins.php` | Prints every coin with its minimum (command line). |

```bash
export PAYGATE_USDC_WALLET=0x… PAYGATE_BTC_WALLET=bc1q… PAYGATE_EVM_WALLET=0x…
export PAYGATE_CALLBACK_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
export PAYGATE_SITE_URL=https://your-public-url/examples
php -S 0.0.0.0:8000 -t examples
```

Callbacks need a public URL, so the examples must be served from a public address. Running them only on localhost is not enough.

## Testing

```bash
composer install
composer test                                         # unit tests, no network
PAYGATE_INTEGRATION=1 composer test:integration       # read-only calls to the live API
```

In your own tests, pass a fake `http_client` to `Client`. `tests/FakeHttpClient.php` shows how. To test your callback handler, create a `CallbackVerifier` with your own test key pair (`new CallbackVerifier(['v1' => $testPublicPem])`) and sign the URL with `openssl_sign()`.

## Security notes

- Always verify the signature, and answer 403 when it fails. A callback that has not been verified proves nothing; the `User-Agent` header can be set by anyone.
- Keep `PAYGATE_CALLBACK_SECRET` on your server only. It stops a valid payment for one order from being replayed against another.
- Credit each `txid_in` once. Callbacks are retried and can arrive more than once.
- Compare the coin and the amount received with the order before releasing goods. Customers can see and edit the card payment link.
- Never use PayGate.to's parameter names in your callback URL.
- Use a self-custodial wallet you control for payouts.

## API reference

**`PayGate\Client`**: `card()`, `crypto()`, `callbacks()`, `getApiDomain()`, `getCheckoutDomain()`

**`PayGate\Card\CardApi`**
- Wallets: `createWallet($payoutWallet, $callbackUrl)`, `createAffiliateWallet(…)`, `createSubAffiliateWallet(…)`, `createMassPayoutWallet($callbackUrl, $shares)`
- Payment link: `paymentUrl($wallet, $amount, $email, $currency, $provider = null, $branding = [])`
- Data: `providers()`, `convertToUsd($amount, $from)`, `paymentStatus($ipnToken)`

**`PayGate\Crypto\CryptoApi`**
- Coin data: `coins($affiliate = false)`, `info($coin, $affiliate = false)`, `price($coin, $fiat)`, `fees($coin, $affiliate = false)`, `convert($coin, $amount, $fiat)`
- Wallets: `createWallet($coin, $payoutWallet, $callbackUrl, $options = [])`, `createAffiliateWallet(…)`, `createMassPayoutWallet(…)`, `massPayoutFees($coin, $count)`
- Display: `qrCode($coin, $address, $amount = null)`
- Hosted page: `createHostedWallet($wallets, $fiatAmount, $fiatCurrency, $callbackUrl)`, `hostedPaymentUrl($wallet, $addFees = false, $branding = [])`
- Status and swaps: `paymentStatus($ipnToken, $coin)`, `swapCoins()`, `createSwap(…)`, `swapStatus($exchangeId)`

**`PayGate\Callback`**
- `CallbackVerifier`: `verify()`, `verifyRequest()`, `addKey()`
- `VerificationResult`: `isValid()`, `getReason()`, `getKeyId()`
- `CallbackData`: see the payment data table above (`PAYGATE_PARAMS` lists the names PayGate.to adds)
- `CallbackToken`: `callbackUrl()`, `sign()`, `verify()`

The API is documented at <https://paygate.to/docs/payment-gateway-api/> (cards) and <https://paygate.to/docs/crypto-payment-api/> (crypto).

## License

MIT. See [LICENSE](LICENSE).

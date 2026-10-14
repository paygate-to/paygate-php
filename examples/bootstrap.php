<?php

/*
 * Shared setup for the examples. Settings come from environment variables so nothing secret is
 * written in the files:
 *
 *   PAYGATE_USDC_WALLET      your USDC (Polygon) wallet for card payouts, 0x...
 *   PAYGATE_BTC_WALLET       your Bitcoin wallet (crypto examples)
 *   PAYGATE_EVM_WALLET       your EVM wallet: ETH, USDT and USDC on ERC20, BEP20, Polygon... (crypto examples)
 *   PAYGATE_CALLBACK_SECRET  64 random hex characters: php -r "echo bin2hex(random_bytes(32));"
 *   PAYGATE_SITE_URL         public URL of this folder, e.g. https://shop.example/paygate
 *   PAYGATE_API_DOMAIN       optional: your dedicated API endpoint (api-yourshop-xxxx.paygate.to)
 *   PAYGATE_CHECKOUT_DOMAIN  optional: your white-label checkout domain
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PayGate\Callback\CallbackToken;
use PayGate\Client;

function setting(string $name, string $default = ''): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

function required_setting(string $name): string
{
    $value = setting($name);
    if ($value === '') {
        http_response_code(500);
        exit('Set the ' . $name . " environment variable (see examples/bootstrap.php).\n");
    }

    return $value;
}

function paygate(): Client
{
    return new Client(array_filter([
        'api_domain' => setting('PAYGATE_API_DOMAIN'),
        'checkout_domain' => setting('PAYGATE_CHECKOUT_DOMAIN'),
    ]));
}

function callback_tokens(): CallbackToken
{
    return new CallbackToken(required_setting('PAYGATE_CALLBACK_SECRET'));
}

/** The registered callback URL, without the order parameters. */
function callback_base(): string
{
    return rtrim(required_setting('PAYGATE_SITE_URL'), '/') . '/callback.php';
}

/**
 * A minimal order store for the examples (a JSON file). Use your own database in production.
 *
 * @return array<string,array<string,mixed>>
 */
function load_orders(): array
{
    $file = orders_file();
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

    return is_array($data) ? $data : [];
}

/** @param array<string,mixed> $order */
function save_order(string $id, array $order): void
{
    $file = orders_file();
    $handle = fopen($file, 'c+');
    flock($handle, LOCK_EX);
    $orders = json_decode((string) stream_get_contents($handle), true) ?: [];
    $orders[$id] = $order;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    flock($handle, LOCK_UN);
    fclose($handle);
}

function orders_file(): string
{
    return setting('PAYGATE_EXAMPLE_ORDERS', sys_get_temp_dir() . '/paygate-example-orders.json');
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/** A query parameter as a string (arrays such as ?amount[]= are ignored). */
function param(string $name, string $default): string
{
    $value = $_GET[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : $default;
}

/** Your payout wallet for a coin: Bitcoin for btc, the EVM wallet for ETH and EVM tokens, null otherwise. */
function payout_wallet_for(string $coin): ?string
{
    $id = PayGate\Crypto\Coin::id($coin);
    if ($id === 'btc') {
        return setting('PAYGATE_BTC_WALLET') ?: null;
    }
    $chain = explode('_', $id, 2)[0];
    $evm = ['eth', 'erc20', 'bep20', 'polygon', 'arbitrum', 'base', 'optimism', 'avax-c', 'linea', 'monad'];
    if (in_array($chain, $evm, true)) {
        return setting('PAYGATE_EVM_WALLET') ?: null;
    }

    return null; // add your wallets for other networks (ltc, sol, trc20...) here
}

/**
 * Records a confirmed payment and updates the order's status. Payments in the same unit add up,
 * a paid order stays paid, and the order is paid once the total reaches $tolerance × $expected.
 *
 * @param array<string,mixed> $order
 *
 * @return array<string,mixed>
 */
function record_payment(array $order, string $txid, string $unit, string $value, string $expected, float $tolerance): array
{
    $order['txids'][] = $txid;
    $order['received'][$unit] = (string) ((float) ($order['received'][$unit] ?? 0) + (float) $value);
    if (($order['status'] ?? '') !== 'paid') {
        $order['status'] = (float) $order['received'][$unit] >= (float) $expected * $tolerance ? 'paid' : 'underpaid';
    }

    return $order;
}

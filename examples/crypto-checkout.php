<?php

/*
 * Crypto checkout on your own page: the customer sees the address, the amount and a QR code.
 *
 *   GET crypto-checkout.php?coin=btc&amount=25&currency=USD
 *   GET crypto-checkout.php?coin=erc20_usdc&amount=25&currency=EUR
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PayGate\Crypto\Coin;
use PayGate\Exception\PayGateException;

$coin = Coin::id(param('coin', 'btc'));
$amount = param('amount', '25');
$currency = strtoupper(param('currency', 'USD'));

$payout = payout_wallet_for($coin);
if ($payout === null) {
    http_response_code(400);
    exit('No payout wallet is configured for ' . h($coin) . '.');
}

$orderId = 'crypto-' . bin2hex(random_bytes(6));
$callback = callback_tokens()->callbackUrl(callback_base(), ['order' => $orderId]);

try {
    $crypto = paygate()->crypto();
    $wallet = $crypto->createWallet($coin, $payout, $callback);
    $quote = $crypto->convert($coin, $amount, $currency);
    $qr = $crypto->qrCode($coin, $wallet->getAddressIn(), $quote['value']);
    $minimum = $crypto->info($coin)['minimum'];
} catch (PayGateException $e) {
    http_response_code(502);
    exit('Payment could not be started: ' . h($e->getMessage()));
}

save_order($orderId, [
    'method' => 'crypto',
    'coin' => $coin,
    'amount' => $amount,
    'currency' => $currency,
    'expected_coin' => $quote['value'],
    'status' => 'pending',
    'ipn_token' => $wallet->getIpnToken(),
    'address_in' => $wallet->getAddressIn(),
]);
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pay with <?= h(strtoupper($coin)) ?></title>
<main style="font-family: system-ui, sans-serif; max-width: 28rem; margin: 2rem auto; padding: 0 1rem">
    <h1>Order <?= h($orderId) ?></h1>
    <p>Send exactly <strong><?= h($quote['value']) ?> <?= h(strtoupper(str_replace('_', ' ', $coin))) ?></strong>
       (<?= h($amount . ' ' . $currency) ?>) to:</p>
    <p><code style="word-break: break-all"><?= h($wallet->getAddressIn()) ?></code></p>
    <img src="<?= h($qr['data_uri']) ?>" width="240" height="240" alt="QR code of the payment address">
    <p><small>Minimum: <?= h($minimum) ?>. Your order updates automatically once the payment is confirmed.</small></p>
</main>

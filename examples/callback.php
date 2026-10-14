<?php

/*
 * Callback handler. PayGate.to calls this URL (with your order parameters plus the payment data)
 * when a payment is made. It must:
 *   1. verify PayGate.to's signature, and fail closed;
 *   2. check the order token, so a payment cannot be credited to another order;
 *   3. credit each payment once, however many times the callback arrives;
 *   4. check the coin and the amount received against the order.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PayGate\Callback\CallbackData;
use PayGate\Crypto\Coin;
use PayGate\Exception\PayGateException;

header('Content-Type: text/plain');

// 1. Signature over your registered callback URL + the raw query string, as received.
$result = paygate()->callbacks()->verifyRequest(callback_base());
if (!$result->isValid()) {
    http_response_code(403);
    error_log('PayGate callback rejected: ' . $result->getReason());
    exit('invalid signature');
}

$data = CallbackData::fromRequest();
$orderId = (string) $data->get('order');

// 2. The order reference must carry the token your server issued.
if (!callback_tokens()->verify($orderId, $data->get(callback_tokens()->getParam()))) {
    http_response_code(403);
    exit('invalid order token');
}

$orders = load_orders();
if (!isset($orders[$orderId])) {
    http_response_code(404);
    exit('unknown order');
}
$order = $orders[$orderId];

// Crypto wallets created with ['pending' => true] first send an unconfirmed notice.
if ($data->isPending()) {
    if ($order['status'] === 'pending') {
        $order['status'] = 'confirming';
        save_order($orderId, $order);
    }
    exit('ok');
}

// 3. A verified callback may arrive more than once: credit each payment once.
$txid = (string) $data->getTxidIn();
if (in_array($txid, $order['txids'] ?? [], true)) {
    exit('ok');
}

// 4. Coin and amount.
$coin = Coin::id((string) $data->getCoin());
$value = (string) $data->getValueCoin();
try {
    $payouts = $data->getPayouts();
} catch (\PayGate\Exception\InvalidArgumentException $e) {
    $payouts = []; // informational only: never let it block the payment
}
$order['payments'][] = ['txid' => $txid, 'coin' => $coin, 'value' => $value, 'payouts' => $payouts];
$isStablecoin = (bool) preg_match('/_(usdc|usdt)$/', $coin);

if ($order['method'] === 'card' && !$isStablecoin) {
    // Card payments arrive in USDC or USDT; anything else was sent to the address directly.
    $order['txids'][] = $txid;
    $order['status'] = $order['status'] === 'paid' ? 'paid' : 'wrong_coin';
} elseif ($order['method'] === 'card') {
    // USDC (or USDT) after the provider's fee. 0.60 tolerates high provider fees while catching
    // a customer who edited the amount in the payment link (the official plugins use the same default).
    $order = record_payment($order, $txid, 'usd', $value, $order['expected_usd'], 0.60);
} elseif ($order['method'] === 'crypto' && $coin !== $order['coin']) {
    // A different token was sent to the address: forwarded to you, but not what was asked for.
    $order['txids'][] = $txid;
    $order['status'] = $order['status'] === 'paid' ? 'paid' : 'wrong_coin';
} elseif ($order['method'] === 'crypto') {
    $order = record_payment($order, $txid, $coin, $value, $order['expected_coin'], 0.98);
} else {
    // Hosted page: the customer chose the coin, so price the order in that coin now.
    try {
        $expected = paygate()->crypto()->convert($coin, $order['amount'], $order['currency'])['value'];
    } catch (PayGateException $e) {
        http_response_code(503); // not "ok": PayGate.to will call again
        exit('price unavailable');
    }
    $order = record_payment($order, $txid, $coin, $value, $expected, 0.95);
}

save_order($orderId, $order);
echo 'ok';

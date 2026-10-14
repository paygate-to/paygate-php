<?php

/*
 * Hosted multi-coin page: the customer chooses the coin on PayGate.to's page (or your white-label
 * domain). Give one wallet per network you accept.
 *
 *   GET hosted-checkout.php?amount=25&currency=USD
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PayGate\Exception\PayGateException;

$amount = param('amount', '25');
$currency = strtoupper(param('currency', 'USD'));

$orderId = 'hosted-' . bin2hex(random_bytes(6));
$callback = callback_tokens()->callbackUrl(callback_base(), ['order' => $orderId]);

try {
    $crypto = paygate()->crypto();
    $hosted = $crypto->createHostedWallet([
        'btc' => setting('PAYGATE_BTC_WALLET'),
        'evm' => setting('PAYGATE_EVM_WALLET'), // ETH, USDT, USDC... on ERC20, BEP20, Polygon, Arbitrum, Base...
    ], $amount, $currency, $callback);

    // true: the customer also pays the estimated network fee. Colours are hex codes or CSS names.
    $payUrl = $crypto->hostedPaymentUrl($hosted, true, ['theme' => '#7c3aed']);
} catch (PayGateException $e) {
    http_response_code(502);
    exit('Payment could not be started: ' . h($e->getMessage()));
}

save_order($orderId, [
    'method' => 'hosted',
    'amount' => $amount,
    'currency' => $currency,
    'status' => 'pending',
    'ipn_token' => $hosted->getIpnToken(),
]);

header('Location: ' . $payUrl, true, 303);

<?php

/*
 * Card checkout: Visa, Mastercard, Apple Pay, Google Pay and more. The customer pays on the hosted
 * page and you receive USDC on Polygon in your own wallet.
 *
 *   GET card-checkout.php?amount=49.90&currency=EUR&email=buyer@example.com
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PayGate\Exception\PayGateException;

// In a real shop these come from the order, never from the query string.
$amount = param('amount', '49.90');
$currency = param('currency', 'USD');
$email = param('email', 'buyer@example.com');

// 1. Your order. Its id goes into the callback URL, signed so it cannot be swapped.
$orderId = 'card-' . bin2hex(random_bytes(6));
$callback = callback_tokens()->callbackUrl(callback_base(), ['order' => $orderId]);

try {
    $card = paygate()->card();

    // The amount you expect in USDC. The payment link is visible to the customer, so the
    // callback compares what actually arrived with this.
    $expectedUsd = $card->convertToUsd($amount, $currency)['value'];

    // 2. One wallet per order. The same callback URL always gives the same wallet.
    $wallet = $card->createWallet(required_setting('PAYGATE_USDC_WALLET'), $callback);

    // 3. Send the customer to the hosted page, which lists the providers that work for them.
    //    Pass a provider id ("wert", "stripe"...) as 5th argument to skip the list.
    $payUrl = $card->paymentUrl($wallet, $amount, $email, $currency);
} catch (PayGateException $e) {
    http_response_code(502);
    exit('Payment could not be started: ' . h($e->getMessage()));
}

save_order($orderId, [
    'method' => 'card',
    'amount' => $amount,
    'currency' => $currency,
    'expected_usd' => $expectedUsd,
    'status' => 'pending',
    'ipn_token' => $wallet->getIpnToken(),
    'address_in' => $wallet->getPolygonAddressIn(),
]);

header('Location: ' . $payUrl, true, 303);

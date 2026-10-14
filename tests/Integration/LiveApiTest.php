<?php

declare(strict_types=1);

namespace PayGate\Tests\Integration;

use PayGate\Client;
use PayGate\Crypto\Coin;
use PHPUnit\Framework\TestCase;

/**
 * Read-only calls against the real API. Opt in with:
 *
 *   PAYGATE_INTEGRATION=1 vendor/bin/phpunit --testsuite integration
 *
 * PAYGATE_API_DOMAIN selects your dedicated endpoint. Set PAYGATE_TEST_EVM_WALLET (a wallet you own)
 * to also create a card and a crypto wallet; nothing is charged.
 */
final class LiveApiTest extends TestCase
{
    /** @var Client */
    private $client;

    protected function setUp(): void
    {
        if (getenv('PAYGATE_INTEGRATION') !== '1') {
            self::markTestSkipped('Set PAYGATE_INTEGRATION=1 to call the live API.');
        }
        $domain = getenv('PAYGATE_API_DOMAIN');
        $this->client = new Client($domain ? ['api_domain' => $domain] : []);
    }

    public function testCoinList(): void
    {
        $coins = $this->client->crypto()->coins();
        self::assertArrayHasKey('btc', $coins);
        self::assertContainsOnlyInstancesOf(Coin::class, $coins);
        self::assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $coins['btc']->getMinimum());
    }

    public function testInfoAndConvert(): void
    {
        $crypto = $this->client->crypto();
        $info = $crypto->info('btc');
        self::assertSame('Bitcoin', $info['coin']);
        self::assertArrayHasKey('USD', $info['prices']);

        $quote = $crypto->convert('btc', '100', 'USD');
        self::assertGreaterThan(0, (float) $quote['value']);

        $fees = $crypto->fees('btc');
        self::assertNotSame('', $fees['coin']);
    }

    public function testCardConversion(): void
    {
        $usd = $this->client->card()->convertToUsd('100', 'EUR');
        self::assertGreaterThan(0, (float) $usd['value']);
    }

    public function testProviders(): void
    {
        self::assertIsArray($this->client->card()->providers());
    }

    public function testWalletCreation(): void
    {
        $wallet = getenv('PAYGATE_TEST_EVM_WALLET');
        if (!$wallet) {
            self::markTestSkipped('Set PAYGATE_TEST_EVM_WALLET to create test wallets.');
        }
        $callback = 'https://example.com/paygate-sdk-test?order=' . bin2hex(random_bytes(4));

        $card = $this->client->card()->createWallet($wallet, $callback);
        self::assertNotSame('', $card->getAddressIn());
        self::assertStringStartsWith('https://', $this->client->card()->paymentUrl($card, '10', 'test@example.com', 'USD'));

        $crypto = $this->client->crypto()->createWallet('polygon_usdc', $wallet, $callback);
        self::assertNotSame('', $crypto->getAddressIn());
    }
}

<?php

declare(strict_types=1);

namespace PayGate\Tests\Unit;

use PayGate\Client;
use PayGate\Crypto\Coin;
use PayGate\Crypto\CryptoApi;
use PayGate\Exception\ApiException;
use PayGate\Exception\InvalidArgumentException;
use PayGate\Tests\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CryptoApiTest extends TestCase
{
    private const CALLBACK = 'https://shop.example/cb.php?order=77';

    /** @var FakeHttpClient */
    private $http;

    /** @var CryptoApi */
    private $crypto;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->crypto = (new Client(['http_client' => $this->http]))->crypto();
    }

    public function testCoinIdentifiers(): void
    {
        self::assertSame('erc20_usdc', Coin::id('erc20/usdc'));
        self::assertSame('erc20/usdc', Coin::path('erc20_usdc'));
        self::assertSame('erc20/usdc', Coin::path('ERC20/USDC'));
        self::assertSame('btc', Coin::path('btc'));
        self::assertSame('arbitrum/usdc.e', Coin::path('arbitrum_usdc.e'));
    }

    public function testCoinsFlattensChains(): void
    {
        $this->http->queue([
            'btc' => ['coin' => 'Bitcoin', 'logo' => 'https://x/btc.png', 'ticker' => 'btc', 'minimum_transaction_coin' => 1.0E-5],
            'erc20' => [
                'usdc' => ['coin' => 'USD Coin', 'logo' => 'https://x/usdc.png', 'ticker' => 'usdc', 'minimum_transaction_coin' => '10'],
                'usdt' => ['coin' => 'Tether', 'logo' => 'https://x/usdt.png', 'ticker' => 'usdt', 'minimum_transaction_coin' => 10],
            ],
        ]);
        $coins = $this->crypto->coins();
        self::assertSame(['btc', 'erc20_usdc', 'erc20_usdt'], array_keys($coins));
        self::assertSame('0.00001', $coins['btc']->getMinimum());
        self::assertSame('erc20/usdc', $coins['erc20_usdc']->getPath());
        self::assertSame('erc20', $coins['erc20_usdc']->getChain());
        self::assertSame('USD Coin (ERC20)', $coins['erc20_usdc']->getLabel());
        self::assertSame('/crypto/info.php', $this->http->lastPath());
    }

    public function testAffiliateCoinList(): void
    {
        $this->http->queue([]);
        $this->crypto->coins(true);
        self::assertSame('/crypto/aff-info.php', $this->http->lastPath());
    }

    public function testInfoKeepsTinyNumbersExact(): void
    {
        $this->http->queue('{"coin":"Bitcoin","minimum":0.00010640000000000001,"prices":{"USD":"60000.00","EUR":51756.5}}');
        $info = $this->crypto->info('btc');
        self::assertSame('Bitcoin', $info['coin']);
        self::assertStringStartsWith('0.0001064', $info['minimum']);
        self::assertStringNotContainsString('E', $info['minimum']);
        self::assertSame(['USD' => '60000.00', 'EUR' => '51756.5'], $info['prices']);
    }

    public function testPrice(): void
    {
        $this->http->queue(['coin' => 'Bitcoin', 'minimum' => '0.0001', 'prices' => ['USD' => '60000.00']]);
        self::assertSame('60000.00', $this->crypto->price('btc', 'usd'));
    }

    public function testMissingPriceThrows(): void
    {
        $this->http->queue(['coin' => 'Bitcoin', 'minimum' => '0.0001', 'prices' => ['USD' => '60000.00']]);
        $this->expectException(ApiException::class);
        $this->crypto->price('btc', 'JPY');
    }

    public function testFees(): void
    {
        $this->http->queue(['status' => 'success', 'estimated_cost' => 2.5E-6, 'estimated_cost_currency' => ['USD' => '0.15']]);
        self::assertSame(['coin' => '0.0000025', 'fiat' => ['USD' => '0.15']], $this->crypto->fees('btc'));
        self::assertSame('/crypto/btc/fees.php', $this->http->lastPath());
    }

    public function testConvert(): void
    {
        $this->http->queue(['status' => 'success', 'value_coin' => '0.00083', 'exchange_rate' => '0.0000166']);
        self::assertSame(['value' => '0.00083', 'exchange_rate' => '0.0000166'], $this->crypto->convert('btc', '49.80', 'USD'));
        self::assertSame('from=usd&value=49.80', $this->http->lastQuery());
    }

    public function testCreateWallet(): void
    {
        $this->http->queue(['address_in' => '0xIn', 'callback_url' => self::CALLBACK, 'ipn_token' => 'aXBu']);
        $wallet = $this->crypto->createWallet('erc20_usdc', '0xMine', self::CALLBACK, ['pending' => true, 'confirmations' => 3]);
        self::assertSame('/crypto/erc20/usdc/wallet.php', $this->http->lastPath());
        self::assertSame('address=0xMine&callback=' . rawurlencode(self::CALLBACK) . '&pending=1&confirmations=3', $this->http->lastQuery());
        self::assertSame('erc20_usdc', $wallet->getCoin());
        self::assertSame('0xIn', $wallet->getAddressIn());
        self::assertSame('aXBu', $wallet->getIpnToken());
    }

    public function testUnknownWalletOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crypto->createWallet('btc', 'bc1q', self::CALLBACK, ['confirm' => 2]);
    }

    /**
     * @dataProvider badCoins
     */
    public function testBadCoinIdentifier(string $coin): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crypto->createWallet($coin, 'bc1q', self::CALLBACK);
    }

    /** @return array<string,array{string}> */
    public static function badCoins(): array
    {
        return [
            'parent dir' => ['../control'],
            'dot segment' => ['erc20/..'],
            'query' => ['btc?x=1'],
            'three segments' => ['a/b/c'],
            'empty' => [' '],
        ];
    }

    public function testAffiliateWallets(): void
    {
        $this->http->queue(['address_in' => 'a'])->queue(['address_in' => 'b']);
        $this->crypto->createAffiliateWallet('trc20_usdt', 'TMine', self::CALLBACK, 'TAff');
        self::assertSame('/crypto/trc20/usdt/affiliate.php', $this->http->lastPath());
        $this->crypto->createAffiliateWallet('trc20_usdt', 'TMine', self::CALLBACK, 'TAff', '0.01', '0.98');
        self::assertSame('/crypto/trc20/usdt/custom-affiliate.php', $this->http->lastPath());
        self::assertStringContainsString('&affiliate_fee=0.01&merchant_fee=0.98', $this->http->lastQuery());
    }

    public function testMassPayout(): void
    {
        $this->http->queue(['address_in' => 'bc1qin']);
        $this->crypto->createMassPayoutWallet('btc', self::CALLBACK, ['bc1qa' => 0.33, 'bc1qb' => 0.33, 'bc1qc' => 0.33], ['pending' => true]);
        $req = $this->http->last();
        self::assertSame('POST', $req['method']);
        self::assertSame('https://api.paygate.to/crypto/btc/mass-payout.php?pending=1', $req['url']);
        self::assertSame(['bc1qa' => 0.33, 'bc1qb' => 0.33, 'bc1qc' => 0.33], json_decode((string) $req['body'], true)['fees']);
    }

    public function testMassPayoutFees(): void
    {
        $this->http->queue(['estimated_cost' => '0.0001', 'estimated_cost_currency' => ['USD' => 6]]);
        self::assertSame(['fee_coin' => '0.0001', 'fee_fiat' => ['USD' => '6']], $this->crypto->massPayoutFees('btc', 3));
        self::assertSame('wallet_count=3', $this->http->lastQuery());
    }

    public function testQrCode(): void
    {
        $this->http->queue(['qr_code' => 'iVBORw0KGgo=', 'payment_uri' => 'bitcoin:bc1qin?amount=0.001']);
        $qr = $this->crypto->qrCode('btc', 'bc1qin', '0.001');
        self::assertSame('data:image/png;base64,iVBORw0KGgo=', $qr['data_uri']);
        self::assertSame('bitcoin:bc1qin?amount=0.001', $qr['payment_uri']);
        self::assertSame('address=bc1qin&amount=0.001', $this->http->lastQuery());
    }

    public function testHostedWallet(): void
    {
        $this->http->queue(['payment_token' => 'tok%2B%3D', 'callback_url' => self::CALLBACK, 'ipn_token' => 'aXBu']);
        $hosted = $this->crypto->createHostedWallet(['BTC' => 'bc1qmine', 'evm' => '0xMine', 'solana' => ''], '25.50', 'eur', self::CALLBACK);
        $req = $this->http->last();
        self::assertSame('https://api.paygate.to/crypto/multi-hosted-wallet.php', $req['url']);
        self::assertSame(
            ['btc' => 'bc1qmine', 'evm' => '0xMine', 'fiat_amount' => 25.5, 'fiat_currency' => 'EUR', 'callback' => self::CALLBACK],
            json_decode((string) $req['body'], true)
        );
        self::assertSame('tok%2B%3D', $hosted->getPaymentToken());

        $url = $this->crypto->hostedPaymentUrl($hosted, true, ['theme' => 'dark', 'domain' => 'pay.myshop.com']);
        self::assertSame('https://checkout.paygate.to/crypto/hosted.php?payment_token=tok%2B%3D&add_fees=1&theme=dark&domain=pay.myshop.com', $url);
    }

    public function testHostedWalletRejectsUnknownNetwork(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown network "eth"');
        $this->crypto->createHostedWallet(['eth' => '0x1'], 10, 'USD', self::CALLBACK);
    }

    public function testHostedWalletNeedsAWallet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crypto->createHostedWallet(['btc' => ' '], 10, 'USD', self::CALLBACK);
    }

    public function testPaymentStatusEncodesTicker(): void
    {
        $this->http->queue(['status' => 'unpaid']);
        $status = $this->crypto->paymentStatus('aXBu%3D', 'bep20_usdt');
        self::assertFalse($status['paid']);
        self::assertSame('ipn_token=aXBu%3D&ticker=bep20%2Fusdt', $this->http->lastQuery());
    }

    public function testFeesFailureIsAnError(): void
    {
        $this->http->queue(['status' => 'failed']);
        $this->expectException(ApiException::class);
        $this->crypto->fees('btc');
    }

    public function testSwapIsNotRetried(): void
    {
        $this->http->queueFailure();
        try {
            $this->crypto->createSwap('btc', 'trc20_usdt', 'TReceive', '0.01', self::CALLBACK);
            self::fail('Expected TransportException');
        } catch (\PayGate\Exception\TransportException $e) {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testSwap(): void
    {
        $this->http->queue(['status' => 'success', 'data' => ['id' => 'x1']]);
        $this->crypto->createSwap('btc', 'trc20_usdt', 'TReceive', '0.01', self::CALLBACK, 'TAff', 0.05);
        self::assertSame('/crypto/btc/aff-swap.php', $this->http->lastPath());
        parse_str($this->http->lastQuery(), $q);
        self::assertSame('trc20/usdt', $q['ticker_to']);
        self::assertSame('0.05', $q['affiliate_fee']);
    }
}

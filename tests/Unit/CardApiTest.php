<?php

declare(strict_types=1);

namespace PayGate\Tests\Unit;

use PayGate\Card\CardApi;
use PayGate\Card\CardWallet;
use PayGate\Client;
use PayGate\Exception\ApiException;
use PayGate\Exception\InvalidArgumentException;
use PayGate\Tests\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CardApiTest extends TestCase
{
    private const WALLET = '0x34653D12778FccF10eE978EfFb16450258d2aFc9';
    private const CALLBACK = 'https://shop.example/paygate/callback.php?order=1001&token=ab cd';

    /** Encrypted address_in exactly as the API returns it: already percent-encoded. */
    private const ADDRESS_IN = 'iZUo%2B5jQ%2FhpVvBEc%3D%3D';

    /** @var FakeHttpClient */
    private $http;

    /** @var CardApi */
    private $card;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->card = (new Client(['http_client' => $this->http, 'checkout_domain' => 'checkout.paygate.to']))->card();
    }

    private function walletResponse(): array
    {
        return [
            'address_in' => self::ADDRESS_IN,
            'polygon_address_in' => '0x32e854bD1270670C832634CA87858fFd9F3e2c78',
            'callback_url' => self::CALLBACK,
            'ipn_token' => 'ZEE2cW8z%3D',
        ];
    }

    public function testCreateWalletEncodesTheCallback(): void
    {
        $this->http->queue($this->walletResponse());
        $wallet = $this->card->createWallet(self::WALLET, self::CALLBACK);

        self::assertSame('/control/wallet.php', $this->http->lastPath());
        self::assertSame(
            'address=' . self::WALLET . '&callback=' . rawurlencode(self::CALLBACK),
            $this->http->lastQuery()
        );
        self::assertSame(self::ADDRESS_IN, $wallet->getAddressIn());
        self::assertSame('0x32e854bD1270670C832634CA87858fFd9F3e2c78', $wallet->getPolygonAddressIn());
        self::assertSame('ZEE2cW8z%3D', $wallet->getIpnToken());
    }

    public function testCallbackMustCarryAnOrderParameter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('?order=1234');
        $this->card->createWallet(self::WALLET, 'https://shop.example/callback.php');
    }

    public function testCallbackMustBeAbsolute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->card->createWallet(self::WALLET, '/callback.php?order=1');
    }

    public function testCallbackMustNotCarryPayGateParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('value_coin');
        $this->card->createWallet(self::WALLET, 'https://shop.example/cb.php?order=1&value_coin=5000');
    }

    public function testInjectedAddressIsEncoded(): void
    {
        $url = $this->card->paymentUrl('abc%2Fdef&domain=evil.example', '10', 'a@b.co', 'USD');
        self::assertStringStartsWith('https://checkout.paygate.to/pay.php?address=abc%252Fdef%26domain%3Devil.example&amount=10', $url);
    }

    public function testMassPayoutJsonIgnoresSerializePrecision(): void
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');
        try {
            $this->http->queue($this->walletResponse());
            $this->card->createMassPayoutWallet(self::CALLBACK, ['0xA' => 0.58, '0xB' => 0.41]);
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
        self::assertStringContainsString('{"0xA":0.58,"0xB":0.41}', (string) $this->http->last()['body']);
    }

    public function testWalletWithSpacesIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->card->createWallet('0x12 34', self::CALLBACK);
    }

    public function testMissingAddressInIsAnApiError(): void
    {
        $this->http->queue(['status' => 'success']);
        $this->expectException(ApiException::class);
        $this->card->createWallet(self::WALLET, self::CALLBACK);
    }

    public function testAffiliateWithoutFeesUsesAffiliateEndpoint(): void
    {
        $this->http->queue($this->walletResponse());
        $this->card->createAffiliateWallet(self::WALLET, self::CALLBACK, '0xAffiliate');
        self::assertSame('/control/affiliate.php', $this->http->lastPath());
        self::assertStringEndsWith('&affiliate=0xAffiliate', $this->http->lastQuery());
    }

    public function testAffiliateWithFeesUsesCustomEndpoint(): void
    {
        $this->http->queue($this->walletResponse());
        $this->card->createAffiliateWallet(self::WALLET, self::CALLBACK, '0xAffiliate', 0.04, '0.95');
        self::assertSame('/control/custom-affiliate.php', $this->http->lastPath());
        self::assertStringEndsWith('&affiliate=0xAffiliate&affiliate_fee=0.04&merchant_fee=0.95', $this->http->lastQuery());
    }

    public function testAffiliateFeesMustTotal099(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('0.99');
        $this->card->createAffiliateWallet(self::WALLET, self::CALLBACK, '0xAffiliate', 0.05, 0.95);
    }

    public function testShareAboveOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('0.01 for 1%');
        $this->card->createAffiliateWallet(self::WALLET, self::CALLBACK, '0xAffiliate', 5, 94);
    }

    public function testSubAffiliate(): void
    {
        $this->http->queue($this->walletResponse());
        $this->card->createSubAffiliateWallet(self::WALLET, self::CALLBACK, '0xAff', '0xSub', 0.02, 0.01, 0.96);
        self::assertSame('/control/custom-sub-affiliate.php', $this->http->lastPath());
        parse_str($this->http->lastQuery(), $q);
        self::assertSame(['0.02', '0.96', '0.01', '0xSub'], [$q['affiliate_fee'], $q['merchant_fee'], $q['sub_affiliate_fee'], $q['sub_affiliate']]);
    }

    public function testMassPayoutPostsJson(): void
    {
        $this->http->queue($this->walletResponse());
        $this->card->createMassPayoutWallet(self::CALLBACK, ['0xA' => 0.5, '0xB' => '0.49']);
        $req = $this->http->last();
        self::assertSame('POST', $req['method']);
        self::assertSame('https://api.paygate.to/control/mass-payout.php', $req['url']);
        self::assertSame('application/json', $req['headers']['Content-Type']);
        self::assertSame(['callback' => self::CALLBACK, 'fees' => ['0xA' => 0.5, '0xB' => 0.49]], json_decode((string) $req['body'], true));
    }

    public function testMassPayoutLimits(): void
    {
        $shares = [];
        for ($i = 0; $i < 20; $i++) {
            $shares['0x' . $i] = 0.0495;
        }
        $this->expectException(InvalidArgumentException::class);
        $this->card->createMassPayoutWallet(self::CALLBACK, $shares);
    }

    public function testPaymentUrlKeepsAddressEncodedOnce(): void
    {
        $url = $this->card->paymentUrl(self::ADDRESS_IN, 49.9, 'buyer+1@example.com', 'eur');
        self::assertSame(
            'https://checkout.paygate.to/pay.php?address=' . self::ADDRESS_IN . '&amount=49.9&email=buyer%2B1%40example.com&currency=EUR',
            $url
        );
    }

    public function testPaymentUrlEncodesPlainAddress(): void
    {
        $wallet = new CardWallet('abc+/=', '', '', '');
        self::assertStringContainsString('address=abc%2B%2F%3D&', $this->card->paymentUrl($wallet, '10', 'a@b.co', 'USD'));
    }

    public function testPaymentUrlWithProvider(): void
    {
        $url = $this->card->paymentUrl(self::ADDRESS_IN, '100', 'a@b.co', 'USD', 'wert');
        self::assertSame('https://checkout.paygate.to/process-payment.php?address=' . self::ADDRESS_IN . '&amount=100&provider=wert&email=a%40b.co&currency=USD', $url);
    }

    public function testPaymentUrlBranding(): void
    {
        $url = $this->card->paymentUrl(self::ADDRESS_IN, '100', 'a@b.co', 'USD', null, [
            'domain' => 'https://pay.myshop.com/',
            'logo' => 'https://myshop.com/logo.png',
            'background' => '#ffffff',
            'theme' => 'red',
            'button' => '#000',
        ]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame('pay.myshop.com', $q['domain']);
        self::assertSame('https://myshop.com/logo.png', $q['logo']);
        self::assertSame('#ffffff', $q['background']);
    }

    public function testBrandingWithProviderIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->card->paymentUrl(self::ADDRESS_IN, '100', 'a@b.co', 'USD', 'wert', ['theme' => 'red']);
    }

    public function testUnknownBrandingKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->card->paymentUrl(self::ADDRESS_IN, '100', 'a@b.co', 'USD', null, ['colour' => 'red']);
    }

    /**
     * @dataProvider badPaymentInput
     *
     * @param mixed $amount
     */
    public function testPaymentUrlValidation($amount, string $email, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->card->paymentUrl(self::ADDRESS_IN, $amount, $email, $currency);
    }

    /** @return array<string,array{mixed,string,string}> */
    public static function badPaymentInput(): array
    {
        return [
            'zero amount' => [0, 'a@b.co', 'USD'],
            'negative amount' => ['-5', 'a@b.co', 'USD'],
            'text amount' => ['ten', 'a@b.co', 'USD'],
            'no email' => ['10', '', 'USD'],
            'bad currency' => ['10', 'a@b.co', 'dollars'],
        ];
    }

    public function testProviders(): void
    {
        $this->http->queue(['providers' => [
            ['id' => 'wert', 'provider_name' => 'Wert.io', 'status' => 'active', 'minimum_currency' => 'USD', 'minimum_amount' => 1],
            ['id' => 'stripe', 'provider_name' => 'Stripe (USA)', 'status' => 'inactive', 'minimum_currency' => 'USD', 'minimum_amount' => 2],
        ]]);
        $providers = $this->card->providers();
        self::assertCount(2, $providers);
        self::assertSame('wert', $providers[0]->getId());
        self::assertTrue($providers[0]->isActive());
        self::assertFalse($providers[1]->isActive());
        self::assertSame('2', $providers[1]->getMinimumAmount());
    }

    public function testConvertToUsd(): void
    {
        $this->http->queue(['status' => 'success', 'value_coin' => '54.20', 'exchange_rate' => '0.92']);
        self::assertSame(['value' => '54.20', 'exchange_rate' => '0.92'], $this->card->convertToUsd('49.90', 'eur'));
        self::assertSame('from=EUR&value=49.90', $this->http->lastQuery());
    }

    public function testConvertUsdNeedsNoRequest(): void
    {
        self::assertSame(['value' => '10', 'exchange_rate' => '1'], $this->card->convertToUsd(10, 'USD'));
        self::assertCount(0, $this->http->requests);
    }

    public function testPaymentStatus(): void
    {
        $this->http->queue(['status' => 'paid', 'value_coin' => '117.59', 'txid_out' => '0xe85e', 'coin' => 'polygon_usdc']);
        $status = $this->card->paymentStatus('ZEE2cW8z%3D');
        self::assertTrue($status['paid']);
        self::assertSame('ipn_token=ZEE2cW8z%3D', $this->http->lastQuery());
    }
}

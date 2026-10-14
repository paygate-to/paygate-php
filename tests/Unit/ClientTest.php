<?php

declare(strict_types=1);

namespace PayGate\Tests\Unit;

use PayGate\Callback\CallbackVerifier;
use PayGate\Client;
use PayGate\Exception\ApiException;
use PayGate\Exception\InvalidArgumentException;
use PayGate\Exception\TransportException;
use PayGate\Tests\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testDefaults(): void
    {
        $client = new Client(['http_client' => new FakeHttpClient()]);
        self::assertSame('api.paygate.to', $client->getApiDomain());
        self::assertSame('checkout.paygate.to', $client->getCheckoutDomain());
        self::assertInstanceOf(CallbackVerifier::class, $client->callbacks());
        self::assertSame($client->card(), $client->card());
    }

    public function testDedicatedDomainIsNormalised(): void
    {
        $client = new Client(['api_domain' => 'https://API-MyShop-1a2b.paygate.to/', 'checkout_domain' => 'pay.example.com', 'http_client' => new FakeHttpClient()]);
        self::assertSame('api-myshop-1a2b.paygate.to', $client->getApiDomain());
        self::assertSame('pay.example.com', $client->getCheckoutDomain());
    }

    public function testRejectsUnknownOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('apiDomain');
        new Client(['apiDomain' => 'x']);
    }

    public function testRejectsBadHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client(['api_domain' => 'not a host']);
    }

    public function testRejectsBadHttpClient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client(['http_client' => new \stdClass()]);
    }

    public function testRequestsUseHttpsAndUserAgent(): void
    {
        $http = (new FakeHttpClient())->queue(['coin' => 'Bitcoin', 'minimum' => '0.0001', 'prices' => ['USD' => '60000']]);
        (new Client(['http_client' => $http]))->crypto()->info('btc');
        $req = $http->last();
        self::assertSame('GET', $req['method']);
        self::assertSame('https://api.paygate.to/crypto/btc/info.php', $req['url']);
        self::assertStringStartsWith('paygate-php/', $req['headers']['User-Agent']);
    }

    public function testGetIsRetriedOnceOnNetworkFailure(): void
    {
        $http = (new FakeHttpClient())->queueFailure()->queue(['coin' => 'Bitcoin', 'minimum' => '0.0001', 'prices' => []]);
        $info = (new Client(['http_client' => $http]))->crypto()->info('btc');
        self::assertSame('Bitcoin', $info['coin']);
        self::assertCount(2, $http->requests);
    }

    public function testRetriesCanBeDisabled(): void
    {
        $http = (new FakeHttpClient())->queueFailure();
        $this->expectException(TransportException::class);
        (new Client(['http_client' => $http, 'retries' => 0]))->crypto()->info('btc');
    }

    public function testPostIsNeverRetried(): void
    {
        $http = (new FakeHttpClient())->queueFailure();
        try {
            (new Client(['http_client' => $http]))->crypto()->createHostedWallet(['btc' => 'bc1qexample'], 10, 'USD', 'https://shop.example/cb?order=1');
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertCount(1, $http->requests);
        }
    }

    /**
     * @dataProvider errorResponses
     */
    public function testErrorResponsesThrowApiException(string $body, int $status): void
    {
        $http = (new FakeHttpClient())->queue($body, $status);
        try {
            (new Client(['http_client' => $http]))->crypto()->info('btc');
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame($status, $e->getStatusCode());
            self::assertSame($body, $e->getResponseBody());
        }
    }

    /** @return array<string,array{string,int}> */
    public static function errorResponses(): array
    {
        return [
            'http 500' => ['{"status":"error","message":"down"}', 500],
            'status error' => ['{"status":"error","message":"invalid wallet"}', 200],
            'not json' => ['<html>Bad gateway</html>', 200],
            'http 404' => ['{"status":"error","message":"not found"}', 404],
        ];
    }

    public function testApiErrorMessageIsSurfaced(): void
    {
        $http = (new FakeHttpClient())->queue(['status' => 'error', 'message' => 'Invalid payout wallet']);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid payout wallet');
        (new Client(['http_client' => $http]))->card()->createWallet('0xabc', 'https://shop.example/cb?order=1');
    }
}

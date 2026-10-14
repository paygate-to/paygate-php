<?php

declare(strict_types=1);

namespace PayGate\Tests\Unit;

use PayGate\Callback\CallbackVerifier;
use PayGate\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CallbackVerifierTest extends TestCase
{
    private const REGISTERED = 'https://www.example.com/orders.php?number=827746841326';

    /** The documented example callback query. */
    private const QUERY = 'number=827746841326&value_coin=105.6&coin=polygon_usdc&txid_in=0xa22a82b4aefbc55f6382e1b5c0b4f0e3c034a654df3bcac431f7fed1942e22bc&txid_out=0x94c2c3e84c2021e6bf377aebf8abf03b49570611bb0c336e357d7f4516f56244&address_in=0x32e854bD1270670C832634CA87858fFd9F3e2c78&value_forwarded_coin=104.016&address_out=%7B%220x34653D12778FccF10eE978EfFb16450258d2aFc9%22%3A%220.0500%22%2C%220x042beb6Ab05814c3A54C4124e368a30c4f7c93FB%22%3A%220.9250%22%7D';

    /** @var resource|\OpenSSLAsymmetricKey */
    private static $privateKey;

    /** @var string */
    private static $publicPem;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::$privateKey = $key;
        self::$publicPem = openssl_pkey_get_details($key)['key'];
    }

    private static function sign(string $data): string
    {
        openssl_sign($data, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function verifier(): CallbackVerifier
    {
        return new CallbackVerifier(['v1' => self::$publicPem]);
    }

    public function testValidSignature(): void
    {
        $signature = self::sign('https://www.example.com/orders.php?' . self::QUERY);
        $result = $this->verifier()->verify(self::REGISTERED, self::QUERY, $signature, 'v1');
        self::assertTrue($result->isValid());
        self::assertSame('', $result->getReason());
        self::assertSame('v1', $result->getKeyId());
    }

    public function testMissingKeyIdMeansV1(): void
    {
        $signature = self::sign('https://www.example.com/orders.php?' . self::QUERY);
        self::assertTrue($this->verifier()->verify(self::REGISTERED, self::QUERY, $signature, null)->isValid());
    }

    public function testAlteredAmountIsRejected(): void
    {
        $signature = self::sign('https://www.example.com/orders.php?' . self::QUERY);
        $forged = str_replace('value_coin=105.6', 'value_coin=1056', self::QUERY);
        $result = $this->verifier()->verify(self::REGISTERED, $forged, $signature, 'v1');
        self::assertFalse($result->isValid());
        self::assertSame('signature_mismatch', $result->getReason());
    }

    public function testRebuiltQueryWouldFailSoRawIsUsed(): void
    {
        // %20 and a lowercase escape: http_build_query() would turn these into "+" and "%7B".
        $query = 'order=A%20B&note=%7bx%7d&value_coin=1';
        $signature = self::sign('https://shop.example/cb.php?' . $query);
        self::assertTrue($this->verifier()->verify('https://shop.example/cb.php?order=A%20B', $query, $signature)->isValid());
        self::assertFalse($this->verifier()->verify('https://shop.example/cb.php', http_build_query(['order' => 'A B', 'note' => '{x}', 'value_coin' => '1']), $signature)->isValid());
    }

    public function testOtherHostOrPathIsRejected(): void
    {
        $signature = self::sign('https://www.example.com/orders.php?' . self::QUERY);
        self::assertSame('signature_mismatch', $this->verifier()->verify('https://evil.example/orders.php', self::QUERY, $signature)->getReason());
        self::assertSame('signature_mismatch', $this->verifier()->verify('https://www.example.com/other.php', self::QUERY, $signature)->getReason());
    }

    public function testSignatureFromAnotherKeyIsRejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_sign('https://www.example.com/orders.php?' . self::QUERY, $raw, $other, OPENSSL_ALGO_SHA256);
        self::assertSame('signature_mismatch', $this->verifier()->verify(self::REGISTERED, self::QUERY, base64_encode($raw))->getReason());
    }

    /**
     * @dataProvider failures
     */
    public function testFailsClosed(?string $signature, ?string $keyId, string $registered, string $reason): void
    {
        $result = $this->verifier()->verify($registered, self::QUERY, $signature, $keyId);
        self::assertFalse($result->isValid());
        self::assertSame($reason, $result->getReason());
    }

    /** @return array<string,array{?string,?string,string,string}> */
    public static function failures(): array
    {
        return [
            'no header' => [null, 'v1', self::REGISTERED, 'signature_absent'],
            'empty header' => ['  ', 'v1', self::REGISTERED, 'signature_absent'],
            'unknown key' => ['c2ln', 'v9', self::REGISTERED, 'unknown_key_id'],
            'path traversal key id' => ['c2ln', '../v1', self::REGISTERED, 'unknown_key_id'],
            'not base64' => ['***', 'v1', self::REGISTERED, 'signature_not_base64'],
            'relative callback' => ['c2ln', 'v1', '/orders.php', 'no_registered_callback'],
            'empty callback' => ['c2ln', 'v1', '', 'no_registered_callback'],
        ];
    }

    public function testBadPublicKey(): void
    {
        $verifier = new CallbackVerifier(['v1' => "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n"]);
        self::assertSame('bad_public_key', $verifier->verify(self::REGISTERED, self::QUERY, 'c2ln')->getReason());
    }

    public function testKeyResolverHandlesRotation(): void
    {
        $asked = [];
        $verifier = new CallbackVerifier([], function (string $id) use (&$asked): ?string {
            $asked[] = $id;

            return $id === 'v2' ? self::$publicPem : null;
        });
        $signature = self::sign('https://www.example.com/orders.php?' . self::QUERY);
        self::assertTrue($verifier->verify(self::REGISTERED, self::QUERY, $signature, 'v2')->isValid());
        self::assertTrue($verifier->verify(self::REGISTERED, self::QUERY, $signature, 'v2')->isValid());
        self::assertSame('unknown_key_id', $verifier->verify(self::REGISTERED, self::QUERY, $signature, 'v3')->getReason());
        self::assertSame(['v2', 'v3'], $asked, 'a resolved key is cached');
    }

    public function testUnknownKeyIdsAreResolvedOnce(): void
    {
        $calls = 0;
        $verifier = new CallbackVerifier([], function () use (&$calls): ?string {
            $calls++;

            return null;
        });
        for ($i = 0; $i < 5; $i++) {
            $verifier->verify(self::REGISTERED, self::QUERY, 'c2ln', 'forged');
        }
        self::assertSame(1, $calls);
    }

    public function testOnlyOneLeadingQuestionMarkIsAccepted(): void
    {
        $signature = self::sign('https://www.example.com/orders.php?' . self::QUERY);
        self::assertTrue($this->verifier()->verify(self::REGISTERED, '?' . self::QUERY, $signature)->isValid());
        self::assertFalse($this->verifier()->verify(self::REGISTERED, '??' . self::QUERY, $signature)->isValid());
    }

    public function testNullQueryMeansNoQuery(): void
    {
        $signature = self::sign('https://shop.example/cb.php');
        self::assertTrue($this->verifier()->verify('https://shop.example/cb.php', null, $signature)->isValid());
    }

    public function testBundledKeyIsThePublishedKey(): void
    {
        $pem = (string) file_get_contents(CallbackVerifier::bundledKeyPath());
        self::assertSame('e6a0d8bf66a5d2f9bbdf6a9c9ca694c0', md5($pem));
        $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
        self::assertSame(2048, $details['bits']);
        // The default verifier loads it and rejects a signature it did not make.
        $signature = self::sign('https://www.example.com/orders.php?' . self::QUERY);
        self::assertSame('signature_mismatch', (new CallbackVerifier())->verify(self::REGISTERED, self::QUERY, $signature, 'v1')->getReason());
    }

    public function testVerifyRequestReadsServerArray(): void
    {
        $signature = self::sign('https://www.example.com/orders.php?' . self::QUERY);
        $server = [
            'REQUEST_URI' => '/orders.php?' . self::QUERY,
            'QUERY_STRING' => 'ignored',
            'HTTP_X_PAYGATE_SIGNATURE' => $signature,
            'HTTP_X_PAYGATE_KEY_ID' => 'v1',
        ];
        self::assertTrue($this->verifier()->verifyRequest(self::REGISTERED, $server)->isValid());

        unset($server['REQUEST_URI']);
        $server['QUERY_STRING'] = self::QUERY;
        self::assertTrue($this->verifier()->verifyRequest(self::REGISTERED, $server)->isValid());
    }

    public function testRawQueryFrom(): void
    {
        self::assertSame('a=1&b=%20', CallbackVerifier::rawQueryFrom(['REQUEST_URI' => '/cb.php?a=1&b=%20#frag']));
        self::assertSame('', CallbackVerifier::rawQueryFrom(['REQUEST_URI' => '/cb.php']));
        self::assertSame('', CallbackVerifier::rawQueryFrom([]));
    }

    public function testCallbackBase(): void
    {
        self::assertSame('https://shop.example:8443/pay/cb.php', CallbackVerifier::callbackBase(' https://shop.example:8443/pay/cb.php?order=1#x '));
        self::assertNull(CallbackVerifier::callbackBase('ftp://shop.example/cb.php'));
    }

    public function testAddKeyValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CallbackVerifier([]))->addKey('v1', 'not a key');
    }
}

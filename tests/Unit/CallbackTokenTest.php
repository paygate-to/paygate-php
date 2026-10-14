<?php

declare(strict_types=1);

namespace PayGate\Tests\Unit;

use PayGate\Callback\CallbackData;
use PayGate\Callback\CallbackToken;
use PayGate\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CallbackTokenTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testRoundTrip(): void
    {
        $tokens = new CallbackToken(self::SECRET);
        $url = $tokens->callbackUrl('https://shop.example/cb.php', ['order' => 'A 1', 'lang' => 'en']);
        self::assertStringStartsWith('https://shop.example/cb.php?order=A%201&lang=en&token=', $url);

        $data = CallbackData::fromQueryString((string) parse_url($url, PHP_URL_QUERY));
        self::assertTrue($tokens->verify($data->get('order'), $data->get('token')));
        self::assertFalse($tokens->verify('A 2', $data->get('token')));
        self::assertFalse($tokens->verify($data->get('order'), null));
        self::assertFalse((new CallbackToken(strrev(self::SECRET)))->verify($data->get('order'), $data->get('token')));
    }

    public function testDeterministic(): void
    {
        $tokens = new CallbackToken(self::SECRET);
        self::assertSame($tokens->callbackUrl('https://s.example/cb?x=1', ['order' => 5]), $tokens->callbackUrl('https://s.example/cb?x=1', ['order' => 5]));
        self::assertStringStartsWith('https://s.example/cb?x=1&order=5&token=', $tokens->callbackUrl('https://s.example/cb?x=1', ['order' => 5]));
    }

    public function testCustomParam(): void
    {
        $tokens = new CallbackToken(self::SECRET, 'sig');
        self::assertStringContainsString('&sig=', $tokens->callbackUrl('https://s.example/cb', ['order' => 5]));
        self::assertSame('sig', $tokens->getParam());
    }

    public function testShortSecretIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CallbackToken('short');
    }

    public function testReservedParam(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CallbackToken(self::SECRET))->callbackUrl('https://s.example/cb', ['token' => 'x']);
    }

    public function testPayGateParameterNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CallbackToken(self::SECRET))->callbackUrl('https://s.example/cb', ['order' => 1, 'value_coin' => '5000']);
    }

    public function testNeedsAnOrderReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CallbackToken(self::SECRET))->callbackUrl('https://s.example/cb', []);
    }
}

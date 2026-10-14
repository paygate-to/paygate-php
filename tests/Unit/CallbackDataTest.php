<?php

declare(strict_types=1);

namespace PayGate\Tests\Unit;

use PayGate\Callback\CallbackData;
use PayGate\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CallbackDataTest extends TestCase
{
    private const QUERY = 'number=827746841326&value_coin=105.6&coin=polygon_usdc&txid_in=0xa22a&txid_out=0x94c2&address_in=0x32e8&value_forwarded_coin=104.016&address_out=%7B%220x34653D12778FccF10eE978EfFb16450258d2aFc9%22%3A%220.0500%22%2C%220x042beb6Ab05814c3A54C4124e368a30c4f7c93FB%22%3A%220.9250%22%7D';

    public function testDocumentedExample(): void
    {
        $data = CallbackData::fromQueryString(self::QUERY);
        self::assertSame('827746841326', $data->get('number'));
        self::assertSame('105.6', $data->getValueCoin());
        self::assertSame('polygon_usdc', $data->getCoin());
        self::assertSame('0xa22a', $data->getTxidIn());
        self::assertSame('0x94c2', $data->getTxidOut());
        self::assertSame('0x32e8', $data->getAddressIn());
        self::assertSame('104.016', $data->getValueForwardedCoin());
        self::assertFalse($data->isPending());
        self::assertSame([
            '0x34653D12778FccF10eE978EfFb16450258d2aFc9' => '0.05',
            '0x042beb6Ab05814c3A54C4124e368a30c4f7c93FB' => '0.925',
        ], $data->getAddressOut());
        // The documented amounts: 0.0500 × 104.016 = 5.2008 and 0.9250 × 104.016 = 96.2148
        self::assertSame([
            '0x34653D12778FccF10eE978EfFb16450258d2aFc9' => '5.2008',
            '0x042beb6Ab05814c3A54C4124e368a30c4f7c93FB' => '96.2148',
        ], $data->getPayouts());
        self::assertSame('96.2148', $data->getPayoutFor('0x042BEB6AB05814C3A54C4124E368A30C4F7C93FB'));
        self::assertNull($data->getPayoutFor('0xnobody'));
    }

    public function testParserKeepsDotsAndLastValue(): void
    {
        $data = CallbackData::fromQueryString('?my.order=5&a=1&a=2&name=J%C3%B6rg+M&flag');
        self::assertSame('5', $data->get('my.order'));
        self::assertSame('2', $data->get('a'));
        self::assertSame('Jörg M', $data->get('name'));
        self::assertTrue($data->has('flag'));
        self::assertSame('', $data->get('flag'));
        self::assertSame('x', $data->get('missing', 'x'));
    }

    /**
     * @dataProvider pendingValues
     */
    public function testPending(string $query, bool $pending): void
    {
        self::assertSame($pending, CallbackData::fromQueryString($query)->isPending());
    }

    /** @return array<string,array{string,bool}> */
    public static function pendingValues(): array
    {
        return [
            'pending=1' => ['pending=1', true],
            'pending=0' => ['pending=0', false],
            'pending=false' => ['pending=false', false],
            'absent' => ['coin=btc', false],
        ];
    }

    public function testFromRequest(): void
    {
        $data = CallbackData::fromRequest(['REQUEST_URI' => '/cb.php?order=9&value_coin=0.001']);
        self::assertSame('9', $data->get('order'));
        self::assertSame(['order' => '9', 'value_coin' => '0.001'], $data->all());
    }

    public function testNoAddressOut(): void
    {
        $data = CallbackData::fromQueryString('value_forwarded_coin=1');
        self::assertSame([], $data->getAddressOut());
        self::assertSame([], $data->getPayouts());
    }

    public function testInvalidAddressOut(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CallbackData::fromQueryString('address_out=%7Bbroken')->getAddressOut();
    }

    public function testPlantedParametersCannotOverridePayGateData(): void
    {
        // An attacker registers a callback carrying value_coin=5000&pending=0; PayGate.to appends the real values.
        $data = CallbackData::fromQueryString('order=123&value_coin=5000&coin=erc20_usdc&pending=0&value_coin=1.00&coin=erc20_usdt&pending=1');
        self::assertSame('1.00', $data->getValueCoin());
        self::assertSame('erc20_usdt', $data->getCoin());
        self::assertTrue($data->isPending());
    }

    public function testExactPayouts(): void
    {
        $data = CallbackData::fromQueryString('value_forwarded_coin=0.123456789012345678&address_out=' . rawurlencode('{"0xA":"0.925","0xB":0.05,"0xC":1.0E-7}'));
        self::assertSame(['0xA' => '0.925', '0xB' => '0.05', '0xC' => '0.0000001'], $data->getAddressOut());
        self::assertSame([
            '0xA' => '0.11419752983641975215',
            '0xB' => '0.0061728394506172839',
            '0xC' => '0.0000000123456789012345678',
        ], $data->getPayouts());
        self::assertSame('0.11419753', $data->getPayouts(8)['0xA']);
    }

    public function testDocumentedCryptoExampleRoundsHalfUp(): void
    {
        $data = CallbackData::fromQueryString('value_forwarded_coin=0.0000053781&address_out=' . rawurlencode('{"0x1":"0.0500","0x2":"0.9250"}'));
        self::assertSame('0.000000268905', $data->getPayoutFor('0x1'));
        self::assertSame('0.00000027', $data->getPayoutFor('0x1', 8));
    }
}

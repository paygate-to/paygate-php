<?php

declare(strict_types=1);

namespace PayGate\Tests\Unit;

use PayGate\Exception\InvalidArgumentException;
use PayGate\Http\ApiRequester;
use PayGate\Support\Amount;
use PayGate\Support\Host;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    /**
     * @dataProvider amounts
     *
     * @param int|float|string $in
     */
    public function testAmountFormat($in, string $out): void
    {
        self::assertSame($out, Amount::format($in));
    }

    /** @return array<string,array{int|float|string,string}> */
    public static function amounts(): array
    {
        return [
            'int' => [10, '10'],
            'float' => [49.9, '49.9'],
            'string' => ['49.90', '49.90'],
            'tiny float' => [0.00001, '0.00001'],
            'comma is not a decimal point' => [' 12.5 ', '12.5'],
        ];
    }

    /**
     * @dataProvider badAmounts
     *
     * @param mixed $in
     */
    public function testBadAmounts($in): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::format($in);
    }

    /** @return array<string,array{mixed}> */
    public static function badAmounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'comma' => ['1,5'],
            'text' => ['abc'],
            'nan' => [NAN],
            'inf' => [INF],
            'empty' => [''],
        ];
    }

    public function testHost(): void
    {
        self::assertSame('api-shop-1.paygate.to', Host::normalise('HTTPS://api-shop-1.paygate.to/control/', 'x'));
        self::assertSame('pay.myshop.com', Host::normalise('pay.myshop.com.', 'x'));
    }

    public function testHostRejectsInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Host::normalise('evil.example@paygate.to', 'x');
    }

    public function testDecimal(): void
    {
        self::assertSame('0.0000001', \PayGate\Support\Decimal::plain('1e-7'));
        self::assertSame('1500', \PayGate\Support\Decimal::plain('1.5E+3'));
        self::assertNull(\PayGate\Support\Decimal::plain('-1'));
        self::assertNull(\PayGate\Support\Decimal::plain('abc'));
        self::assertSame('96.2148', \PayGate\Support\Decimal::multiply('104.016', '0.9250'));
        self::assertSame('0.000000268905', \PayGate\Support\Decimal::multiply('0.0000053781', '0.0500'));
        self::assertSame('10', \PayGate\Support\Decimal::round('9.995', 2));
        self::assertSame('0', \PayGate\Support\Decimal::round('0.004', 2));
    }

    public function testQueryString(): void
    {
        self::assertSame('?a=1%202&c=x%2By', ApiRequester::queryString(['a' => '1 2', 'b' => '', 'n' => null, 'c' => 'x+y']));
        self::assertSame('?t=ab%3D', ApiRequester::queryString(['t' => 'ab%3D'], ['t']));
        self::assertSame('?t=ab%3D', ApiRequester::queryString(['t' => 'ab='], ['t']));
        self::assertSame('?t=a%2Fb%26x%3D1', ApiRequester::queryString(['t' => 'a/b&x=1'], ['t']));
        self::assertSame('?t=a%252F%26x%3D1', ApiRequester::queryString(['t' => 'a%2F&x=1'], ['t']));
        self::assertSame('', ApiRequester::queryString([]));
    }
}

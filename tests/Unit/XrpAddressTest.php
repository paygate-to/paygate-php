<?php

declare(strict_types=1);

namespace PayGate\Tests\Unit;

use PayGate\Crypto\XrpAddress;
use PHPUnit\Framework\TestCase;

/** Vectors made with xrpl-py's classic_address_to_xaddress(). */
final class XrpAddressTest extends TestCase
{
    public function testXAddressesBecomeTheClassicFormPayGateReports(): void
    {
        self::assertSame('rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn', XrpAddress::payoutForm('X7qvLs7gSnNoKvZzNWUT2e8st17QPY64PPe7zriLNuJszeg'));
        self::assertSame('rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn?dt=0', XrpAddress::payoutForm('X7qvLs7gSnNoKvZzNWUT2e8st17QPY8U2gfaCcGHhuvg4XJ'));
        self::assertSame('rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn?dt=777', XrpAddress::payoutForm(' X7qvLs7gSnNoKvZzNWUT2e8st17QPY84SkdtoNKGbhoPHEW '));
        self::assertSame('rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn?dt=4294967295', XrpAddress::payoutForm('X7qvLs7gSnNoKvZzNWUT2e8st17QPY1pmxZ6cPwpL33FTog'));
        self::assertSame(['classic' => 'rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn', 'tag' => 777], XrpAddress::decodeXAddress('X7qvLs7gSnNoKvZzNWUT2e8st17QPY84SkdtoNKGbhoPHEW'));
    }

    public function testOtherValuesAreLeftAlone(): void
    {
        foreach (['rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn', 'rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn?dt=777', '0xF977814e90dA44bFA03b6295A0616a897441aceC',
            'T7mSk9dCJp8FmSLbWJAN7eXx4KrUu3SQPvbMauYoBC2G63L',   // testnet X-address
            'X7qvLs7gSnNoKvZzNWUT2e8st17QPY84SkdtoNKGbhoPHEX',   // broken checksum
            'X0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl0OIl'] as $value) {
            self::assertSame($value, XrpAddress::payoutForm($value));
            self::assertNull(XrpAddress::decodeXAddress($value));
        }
    }
}

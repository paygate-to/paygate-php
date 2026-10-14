<?php

declare(strict_types=1);

namespace PayGate\Crypto;

/**
 * A receiving address created for one order and one coin.
 */
final class CryptoWallet
{
    /** @var string */
    private $coin;

    /** @var string */
    private $addressIn;

    /** @var string */
    private $callbackUrl;

    /** @var string */
    private $ipnToken;

    /** @var array<mixed> */
    private $raw;

    /**
     * @param array<mixed> $raw
     */
    public function __construct(string $coin, string $addressIn, string $callbackUrl, string $ipnToken, array $raw = [])
    {
        $this->coin = $coin;
        $this->addressIn = $addressIn;
        $this->callbackUrl = $callbackUrl;
        $this->ipnToken = $ipnToken;
        $this->raw = $raw;
    }

    /** Coin id, e.g. "btc" or "trc20_usdt". */
    public function getCoin(): string
    {
        return $this->coin;
    }

    /** The address the customer pays to. Show it with the QR code. */
    public function getAddressIn(): string
    {
        return $this->addressIn;
    }

    public function getCallbackUrl(): string
    {
        return $this->callbackUrl;
    }

    /** Token for paymentStatus() and for PayGate.to support. */
    public function getIpnToken(): string
    {
        return $this->ipnToken;
    }

    /** @return array<mixed> The full API response. */
    public function toArray(): array
    {
        return $this->raw;
    }
}

<?php

declare(strict_types=1);

namespace PayGate\Card;

/**
 * Result of creating a card payment wallet: one per order.
 */
final class CardWallet
{
    /** @var string */
    private $addressIn;

    /** @var string */
    private $polygonAddressIn;

    /** @var string */
    private $callbackUrl;

    /** @var string */
    private $ipnToken;

    /** @var array<mixed> */
    private $raw;

    /**
     * @param array<mixed> $raw
     */
    public function __construct(string $addressIn, string $polygonAddressIn, string $callbackUrl, string $ipnToken, array $raw = [])
    {
        $this->addressIn = $addressIn;
        $this->polygonAddressIn = $polygonAddressIn;
        $this->callbackUrl = $callbackUrl;
        $this->ipnToken = $ipnToken;
        $this->raw = $raw;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['address_in'] ?? ''),
            (string) ($data['polygon_address_in'] ?? ''),
            (string) ($data['callback_url'] ?? ''),
            (string) ($data['ipn_token'] ?? ''),
            $data
        );
    }

    /** Encrypted address for the payment link, exactly as PayGate.to returned it (already URL-encoded). */
    public function getAddressIn(): string
    {
        return $this->addressIn;
    }

    /** The order's Polygon receiving address in clear, handy as a payment reference. */
    public function getPolygonAddressIn(): string
    {
        return $this->polygonAddressIn;
    }

    /** The callback URL PayGate.to registered for this wallet. */
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

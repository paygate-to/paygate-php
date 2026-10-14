<?php

declare(strict_types=1);

namespace PayGate\Crypto;

/**
 * Result of preparing the hosted multi-coin page for one order.
 */
final class HostedWallet
{
    /** @var string */
    private $paymentToken;

    /** @var string */
    private $callbackUrl;

    /** @var string */
    private $ipnToken;

    /** @var array<mixed> */
    private $raw;

    /**
     * @param array<mixed> $raw
     */
    public function __construct(string $paymentToken, string $callbackUrl, string $ipnToken, array $raw = [])
    {
        $this->paymentToken = $paymentToken;
        $this->callbackUrl = $callbackUrl;
        $this->ipnToken = $ipnToken;
        $this->raw = $raw;
    }

    /** Token for the hosted page URL, exactly as returned (already URL-encoded). */
    public function getPaymentToken(): string
    {
        return $this->paymentToken;
    }

    public function getCallbackUrl(): string
    {
        return $this->callbackUrl;
    }

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

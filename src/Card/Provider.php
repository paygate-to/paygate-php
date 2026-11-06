<?php

declare(strict_types=1);

namespace PayGate\Card;

/**
 * A card payment provider with its status, fees and minimum order amount.
 */
final class Provider
{
    /** @var string */
    private $id;

    /** @var string */
    private $name;

    /** @var string */
    private $status;

    /** @var string */
    private $minimumCurrency;

    /** @var string */
    private $minimumAmount;

    /** @var string */
    private $fees;

    public function __construct(string $id, string $name, string $status, string $minimumCurrency, string $minimumAmount, string $fees = '')
    {
        $this->id = $id;
        $this->name = $name;
        $this->status = $status;
        $this->minimumCurrency = $minimumCurrency;
        $this->minimumAmount = $minimumAmount;
        $this->fees = $fees;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['provider_name'] ?? ($data['id'] ?? '')),
            (string) ($data['status'] ?? ''),
            (string) ($data['minimum_currency'] ?? 'USD'),
            (string) ($data['minimum_amount'] ?? '0'),
            (string) ($data['fees'] ?? '')
        );
    }

    /** Value for the provider parameter of a payment link, e.g. "stripe". */
    public function getId(): string
    {
        return $this->id;
    }

    /** Human readable name, e.g. "Stripe (US/EU)". */
    public function getName(): string
    {
        return $this->name;
    }

    /** "active", "unstable", "redirected"... */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return strtolower($this->status) === 'active';
    }

    public function getMinimumCurrency(): string
    {
        return $this->minimumCurrency;
    }

    public function getMinimumAmount(): string
    {
        return $this->minimumAmount;
    }

    /**
     * Fees for payments through this provider as PayGate.to returns them, e.g. "2% + Provider".
     * Show it as it is rather than parsing it; empty when the API sends no fees.
     */
    public function getFees(): string
    {
        return $this->fees;
    }
}

<?php

declare(strict_types=1);

namespace PayGate\Crypto;

/**
 * A coin or token PayGate.to accepts.
 *
 * Identifiers come in two forms that the SDK accepts interchangeably:
 *  - the id, as it appears in callbacks: "btc", "erc20_usdc", "trc20_usdt"
 *  - the API path: "btc", "erc20/usdc", "trc20/usdt"
 */
final class Coin
{
    /** @var string */
    private $id;

    /** @var string */
    private $ticker;

    /** @var string */
    private $chain;

    /** @var string */
    private $name;

    /** @var string */
    private $logo;

    /** @var string */
    private $minimum;

    public function __construct(string $id, string $ticker, string $chain, string $name, string $logo, string $minimum)
    {
        $this->id = $id;
        $this->ticker = $ticker;
        $this->chain = $chain;
        $this->name = $name;
        $this->logo = $logo;
        $this->minimum = $minimum;
    }

    /** Id used in callbacks and in this SDK, e.g. "erc20_usdc" or "btc". */
    public function getId(): string
    {
        return $this->id;
    }

    /** Path used in API URLs, e.g. "erc20/usdc" or "btc". */
    public function getPath(): string
    {
        return self::path($this->id);
    }

    /** Ticker inside its chain, e.g. "usdc". */
    public function getTicker(): string
    {
        return $this->ticker;
    }

    /** Chain for tokens, e.g. "erc20"; empty for native coins such as btc. */
    public function getChain(): string
    {
        return $this->chain;
    }

    /** Name from the coin list, e.g. "USD Coin". */
    public function getName(): string
    {
        return $this->name;
    }

    /** Name with the chain for tokens, e.g. "USD Coin (ERC20)". */
    public function getLabel(): string
    {
        return $this->chain === '' ? $this->name : $this->name . ' (' . strtoupper($this->chain) . ')';
    }

    /** Logo URL from PayGate.to. */
    public function getLogo(): string
    {
        return $this->logo;
    }

    /** Smallest amount, in the coin, that is forwarded. Less than this is lost. */
    public function getMinimum(): string
    {
        return $this->minimum;
    }

    /** "erc20/usdc" or "erc20_usdc" -> "erc20_usdc". */
    public static function id(string $coin): string
    {
        $coin = strtolower(trim($coin, " \t\n\r\0\x0B/"));

        return str_replace('/', '_', $coin);
    }

    /** "erc20_usdc" or "erc20/usdc" -> "erc20/usdc". Only the first underscore separates chain and ticker. */
    public static function path(string $coin): string
    {
        $coin = strtolower(trim($coin, " \t\n\r\0\x0B/"));
        if (strpos($coin, '/') !== false) {
            return $coin;
        }
        $pos = strpos($coin, '_');

        return $pos === false ? $coin : substr($coin, 0, $pos) . '/' . substr($coin, $pos + 1);
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->getPath(),
            'ticker' => $this->ticker,
            'chain' => $this->chain,
            'name' => $this->name,
            'label' => $this->getLabel(),
            'logo' => $this->logo,
            'minimum' => $this->minimum,
        ];
    }
}

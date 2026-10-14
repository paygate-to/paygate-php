<?php

declare(strict_types=1);

namespace PayGate;

use PayGate\Callback\CallbackVerifier;
use PayGate\Card\CardApi;
use PayGate\Crypto\CryptoApi;
use PayGate\Exception\InvalidArgumentException;
use PayGate\Http\ApiRequester;
use PayGate\Http\CurlHttpClient;
use PayGate\Http\HttpClientInterface;
use PayGate\Support\Host;

/**
 * Entry point of the PayGate.to PHP SDK.
 *
 *     $paygate = new PayGate\Client();                                   // shared api.paygate.to
 *     $paygate = new PayGate\Client(['api_domain' => 'api-myshop-1a2b3c.paygate.to']); // your dedicated endpoint
 *
 *     $paygate->card();    // card payments (Visa, Mastercard, Apple Pay, Google Pay...), payouts in USDC
 *     $paygate->crypto();  // crypto payments with a QR code on your site, or the hosted multi-coin page
 *     $paygate->callbacks(); // verify PayGate.to's signature on payment confirmations
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_API_DOMAIN = 'api.paygate.to';
    public const DEFAULT_CHECKOUT_DOMAIN = 'checkout.paygate.to';

    /** @var ApiRequester */
    private $requester;

    /** @var string */
    private $checkoutHost;

    /** @var CardApi|null */
    private $card;

    /** @var CryptoApi|null */
    private $crypto;

    /** @var CallbackVerifier|null */
    private $callbacks;

    /**
     * @param array{
     *     api_domain?: string,
     *     checkout_domain?: string,
     *     timeout?: float|int,
     *     connect_timeout?: float|int,
     *     retries?: int,
     *     http_client?: HttpClientInterface,
     *     user_agent?: string
     * } $options
     *
     * api_domain      Host for API calls. Default api.paygate.to; use your free dedicated endpoint in production.
     * checkout_domain Host of the hosted payment pages. Default checkout.paygate.to, or your white-label domain.
     * timeout         Seconds before a request is abandoned (default 30).
     * connect_timeout Seconds allowed to connect (default 10).
     * retries         Extra attempts for GET requests that fail at the network level (default 1). POST is never retried.
     * http_client     Your own HttpClientInterface implementation.
     * user_agent      Overrides the User-Agent header.
     */
    public function __construct(array $options = [])
    {
        $known = ['api_domain', 'checkout_domain', 'timeout', 'connect_timeout', 'retries', 'http_client', 'user_agent'];
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown option(s): ' . implode(', ', $unknown) . '. Known options: ' . implode(', ', $known) . '.');
        }

        $apiHost = Host::normalise(isset($options['api_domain']) && $options['api_domain'] !== '' ? (string) $options['api_domain'] : self::DEFAULT_API_DOMAIN, 'api_domain');
        $this->checkoutHost = Host::normalise(isset($options['checkout_domain']) && $options['checkout_domain'] !== '' ? (string) $options['checkout_domain'] : self::DEFAULT_CHECKOUT_DOMAIN, 'checkout_domain');

        $http = $options['http_client'] ?? null;
        if ($http !== null && !$http instanceof HttpClientInterface) {
            throw new InvalidArgumentException('http_client must implement ' . HttpClientInterface::class . '.');
        }
        if ($http === null) {
            $http = new CurlHttpClient((float) ($options['timeout'] ?? 30), (float) ($options['connect_timeout'] ?? 10));
        }

        $userAgent = isset($options['user_agent']) && $options['user_agent'] !== '' ? (string) $options['user_agent'] : 'paygate-php/' . self::VERSION;
        $this->requester = new ApiRequester($http, $apiHost, $userAgent, (int) ($options['retries'] ?? 1));
    }

    /** Card payments: wallets, hosted payment links, providers, conversion, status. */
    public function card(): CardApi
    {
        if ($this->card === null) {
            $this->card = new CardApi($this->requester, $this->checkoutHost);
        }

        return $this->card;
    }

    /** Crypto payments: coin list, per-coin wallets and QR codes, hosted multi-coin page, status. */
    public function crypto(): CryptoApi
    {
        if ($this->crypto === null) {
            $this->crypto = new CryptoApi($this->requester, $this->checkoutHost);
        }

        return $this->crypto;
    }

    /** Verifier for PayGate.to's signature on payment confirmations, with the published key bundled. */
    public function callbacks(): CallbackVerifier
    {
        if ($this->callbacks === null) {
            $this->callbacks = new CallbackVerifier();
        }

        return $this->callbacks;
    }

    /** The API host in use (for example api.paygate.to). */
    public function getApiDomain(): string
    {
        return $this->requester->apiHost();
    }

    /** The checkout host in use (for example checkout.paygate.to). */
    public function getCheckoutDomain(): string
    {
        return $this->checkoutHost;
    }
}

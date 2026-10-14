<?php

declare(strict_types=1);

namespace PayGate\Callback;

/**
 * Outcome of CallbackVerifier::verify(). When isValid() is false, getReason() says why:
 *
 *  signature_absent        no X-PayGate-Signature header
 *  openssl_unavailable     the PHP openssl extension is not loaded
 *  unknown_key_id          X-PayGate-Key-Id names a key this verifier does not have
 *  bad_public_key          the configured key is not a valid PEM public key
 *  signature_not_base64    the header is not valid base64
 *  no_registered_callback  the registered callback URL passed to verify() is empty or not absolute
 *  signature_mismatch      the signature does not match: forged, altered or not from PayGate.to
 *  openssl_error           openssl_verify() failed internally
 */
final class VerificationResult
{
    /** @var bool */
    private $valid;

    /** @var string */
    private $reason;

    /** @var string */
    private $keyId;

    private function __construct(bool $valid, string $reason, string $keyId)
    {
        $this->valid = $valid;
        $this->reason = $reason;
        $this->keyId = $keyId;
    }

    /** @internal */
    public static function valid(string $keyId): self
    {
        return new self(true, '', $keyId);
    }

    /** @internal */
    public static function invalid(string $reason, string $keyId = ''): self
    {
        return new self(false, $reason, $keyId);
    }

    /** True only when the signature was checked and matches. */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /** Empty when valid, otherwise one of the codes listed on this class. */
    public function getReason(): string
    {
        return $this->reason;
    }

    /** The key id the signature was checked against ("v1"), empty if no check took place. */
    public function getKeyId(): string
    {
        return $this->keyId;
    }
}

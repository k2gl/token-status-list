<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList;

use K2gl\Dsse\Verifier;
use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use K2gl\TokenStatusList\Internal\JwtParts;
use stdClass;

/**
 * Validates a Status List Token in JWT format (Section 5.1, Section 8.3 steps
 * 3–5): `typ`, an allow-listed `alg`, the signature, the required claims and
 * the time window. Fail-closed — anything unexpected is rejected.
 *
 * ```php
 * $token = (new StatusListTokenVerifier)->verify($compact, $issuerKey, expectedUri: $reference->uri);
 * $token->status($reference)->isValid();
 * ```
 */
final class StatusListTokenVerifier
{
    public const TYPE = 'statuslist+jwt';

    public const DEFAULT_ALGORITHMS = ['ES256', 'ES384', 'ES512', 'EdDSA', 'RS256', 'RS384', 'RS512'];

    /**
     * @param list<string> $allowedAlgorithms JOSE `alg` values accepted in the header
     * @param int|null $clock unix time to validate against; null for the system clock
     * @param int $clockLeewaySeconds tolerance for `iat`/`exp` against clock skew
     * @param int $maxListBytes decompressed Status List size limit
     */
    public function __construct(
        private readonly array $allowedAlgorithms = self::DEFAULT_ALGORITHMS,
        private readonly ?int $clock = null,
        private readonly int $clockLeewaySeconds = 0,
        private readonly int $maxListBytes = StatusList::DEFAULT_MAX_BYTES,
    ) {}

    /**
     * @param Verifier|KeyResolver $key the issuer's key, or how to find it
     * @param string|null $expectedUri the `uri` from the Referenced Token; `sub` must equal it
     */
    public function verify(string $compact, Verifier|KeyResolver $key, ?string $expectedUri = null): StatusListToken
    {
        $jwt = JwtParts::parse($compact);
        $header = $jwt->header();
        $this->assertHeader($header);
        $payload = $jwt->payload();

        $verifier = $key instanceof KeyResolver ? $key->resolve($header, $payload) : $key;

        if (! $verifier->verify($jwt->signingInput(), $jwt->signature())) {
            throw new InvalidStatusListTokenException('The Status List Token signature is invalid.');
        }

        $subject = $payload->sub ?? null;

        if (! is_string($subject) || $subject === '') {
            throw new InvalidStatusListTokenException('The Status List Token has no "sub" claim.');
        }

        if ($expectedUri !== null && $subject !== $expectedUri) {
            throw new InvalidStatusListTokenException(sprintf(
                'The Status List Token subject "%s" does not match the referenced URI "%s".',
                $subject,
                $expectedUri,
            ));
        }

        $issuedAt = $payload->iat ?? null;

        if (! is_int($issuedAt)) {
            throw new InvalidStatusListTokenException('The Status List Token has no integer "iat" claim.');
        }

        $expiresAt = $payload->exp ?? null;

        if ($expiresAt !== null && ! is_int($expiresAt)) {
            throw new InvalidStatusListTokenException('The Status List Token "exp" claim must be an integer.');
        }

        $ttl = $payload->ttl ?? null;

        if ($ttl !== null && (! is_int($ttl) && ! is_float($ttl) || $ttl <= 0)) {
            throw new InvalidStatusListTokenException('The Status List Token "ttl" claim must be a positive number.');
        }

        $this->assertTimeWindow($issuedAt, $expiresAt);
        $statusList = $payload->status_list ?? null;

        if (! $statusList instanceof stdClass) {
            throw new InvalidStatusListTokenException('The Status List Token has no "status_list" claim.');
        }

        return new StatusListToken(
            compact: $compact,
            header: $header,
            payload: $payload,
            subject: $subject,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            ttl: $ttl === null ? null : (int) $ttl,
            statusList: StatusList::fromArray($statusList, $this->maxListBytes),
        );
    }

    /** The unix time this verifier validates against. */
    public function now(): int
    {
        return $this->clock ?? time();
    }

    private function assertHeader(stdClass $header): void
    {
        $type = $header->typ ?? null;

        // RFC 7515 Section 4.1.9: `typ` is compared case-insensitively and the
        // "application/" prefix may be omitted.
        if (! is_string($type) || strtolower(preg_replace('#^application/#i', '', $type) ?? '') !== self::TYPE) {
            throw new InvalidStatusListTokenException(sprintf('The Status List Token "typ" header must be "%s".', self::TYPE));
        }

        $algorithm = $header->alg ?? null;

        if (! is_string($algorithm) || ! in_array($algorithm, $this->allowedAlgorithms, true)) {
            throw new InvalidStatusListTokenException(sprintf(
                'The Status List Token algorithm "%s" is not allowed (allowed: %s).',
                is_string($algorithm) ? $algorithm : '',
                implode(', ', $this->allowedAlgorithms),
            ));
        }

        // RFC 7515 Section 4.1.11: a JWS with critical extensions we do not
        // understand is invalid — and this package understands none.
        if (isset($header->crit)) {
            throw new InvalidStatusListTokenException('The Status List Token uses a critical header extension.');
        }
    }

    private function assertTimeWindow(int $issuedAt, ?int $expiresAt): void
    {
        $now = $this->now();

        if ($issuedAt > $now + $this->clockLeewaySeconds) {
            throw new InvalidStatusListTokenException('The Status List Token is issued in the future.');
        }

        if ($expiresAt !== null && $expiresAt + $this->clockLeewaySeconds <= $now) {
            throw new InvalidStatusListTokenException('The Status List Token has expired.');
        }
    }
}

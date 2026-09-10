<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList;

use K2gl\Dsse\EcdsaP256Signer;
use K2gl\Dsse\EcdsaP384Signer;
use K2gl\Dsse\EcdsaP521Signer;
use K2gl\Dsse\Ed25519Signer;
use K2gl\Dsse\Signer;
use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use K2gl\TokenStatusList\Internal\Base64Url;
use K2gl\TokenStatusList\Internal\Json;

/**
 * Issues Status List Tokens in JWT format (Section 5.1), signed by a k2gl/dsse
 * {@see Signer} — which already emits raw JOSE-style signatures.
 *
 * ```php
 * $issuer = new StatusListTokenIssuer(EcdsaP256Signer::fromPem($pem));
 * $compact = $issuer->issue('https://example.com/statuslists/1', $statusList, ttl: 43200);
 * ```
 */
final class StatusListTokenIssuer
{
    private readonly string $algorithm;

    /**
     * @param string|null $algorithm the JOSE `alg` the signer's signatures
     *     correspond to; inferred for the ECDSA and Ed25519 signers of k2gl/dsse
     */
    public function __construct(private readonly Signer $signer, ?string $algorithm = null)
    {
        $this->algorithm = $algorithm ?? self::inferAlgorithm($signer);
    }

    /**
     * @param string $uri the Status List Token's own URI (`sub`); Referenced Tokens point at it
     * @param int|null $issuedAt `iat`; null for now
     * @param int|null $expiresAt `exp`, when the token must no longer be relied upon
     * @param int|null $ttl seconds a consumer may cache the token
     * @param array<string, mixed> $claims additional payload claims (`sub`, `iat`, `exp`, `ttl` and `status_list` win)
     * @param array<string, mixed> $header additional header parameters (`alg` and `typ` win)
     */
    public function issue(
        string $uri,
        StatusList $statusList,
        ?int $issuedAt = null,
        ?int $expiresAt = null,
        ?int $ttl = null,
        array $claims = [],
        array $header = [],
    ): string {
        $issuedAt ??= time();

        if ($expiresAt !== null && $expiresAt <= $issuedAt) {
            throw new InvalidStatusListTokenException('A Status List Token expires after it is issued.');
        }

        if ($ttl !== null && $ttl <= 0) {
            throw new InvalidStatusListTokenException('The "ttl" of a Status List Token is a positive number of seconds.');
        }

        $joseHeader = [];
        $keyId = $this->signer->keyId();

        if ($keyId !== null) {
            $joseHeader['kid'] = $keyId;
        }

        $joseHeader = array_merge($joseHeader, $header, [
            'alg' => $this->algorithm,
            'typ' => StatusListTokenVerifier::TYPE,
        ]);

        $payload = array_merge($claims, [
            'sub' => $uri,
            'iat' => $issuedAt,
            'status_list' => $statusList->toArray(),
        ]);

        if ($expiresAt !== null) {
            $payload['exp'] = $expiresAt;
        }

        if ($ttl !== null) {
            $payload['ttl'] = $ttl;
        }

        $signingInput = Base64Url::encode(Json::encode($joseHeader)) . '.' . Base64Url::encode(Json::encode($payload));

        return $signingInput . '.' . Base64Url::encode($this->signer->sign($signingInput));
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    private static function inferAlgorithm(Signer $signer): string
    {
        return match (true) {
            $signer instanceof EcdsaP256Signer => 'ES256',
            $signer instanceof EcdsaP384Signer => 'ES384',
            $signer instanceof EcdsaP521Signer => 'ES512',
            $signer instanceof Ed25519Signer => 'EdDSA',
            default => throw new InvalidStatusListTokenException(sprintf(
                'Pass the JOSE algorithm explicitly: it cannot be inferred from %s.',
                $signer::class,
            )),
        };
    }
}

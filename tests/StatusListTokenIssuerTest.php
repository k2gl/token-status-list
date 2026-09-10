<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Tests;

use K2gl\Dsse\Ed25519Signer;
use K2gl\Dsse\Ed25519Verifier;
use K2gl\Dsse\RsaSigner;
use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use K2gl\TokenStatusList\Internal\Base64Url;
use K2gl\TokenStatusList\StatusListTokenIssuer;
use K2gl\TokenStatusList\StatusListTokenVerifier;
use K2gl\TokenStatusList\Tests\Support\TokenStatusListTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(StatusListTokenIssuer::class)]
final class StatusListTokenIssuerTest extends TokenStatusListTestCase
{
    public function testIssuesTheSection51Example(): void
    {
        // arrange
        $issuer = new StatusListTokenIssuer(self::localSigner(keyId: '12'));

        // act
        $compact = $issuer->issue(
            uri: 'https://example.com/statuslists/1',
            statusList: self::exampleList(),
            issuedAt: 1686920170,
            expiresAt: 2291720170,
            ttl: 43200,
        );
        [$header, $payload, $signature] = explode('.', $compact);

        // assert: header
        fact((string) Base64Url::decode($header))->matchesJson([
            'kid' => '12',
            'alg' => 'ES256',
            'typ' => 'statuslist+jwt',
        ]);

        // assert: payload matches the specification's example claims
        fact((string) Base64Url::decode($payload))->matchesJson([
            'sub' => 'https://example.com/statuslists/1',
            'iat' => 1686920170,
            'status_list' => ['bits' => 1, 'lst' => 'eNrbuRgAAhcBXQ'],
            'exp' => 2291720170,
            'ttl' => 43200,
        ]);

        // assert: the signature verifies over header.payload
        fact(self::localVerifier()->verify($header . '.' . $payload, (string) Base64Url::decode($signature)))->true();
    }

    public function testOmitsOptionalClaimsWhenNotGiven(): void
    {
        // act
        $compact = (new StatusListTokenIssuer(self::localSigner()))->issue(self::LIST_URI, self::exampleList(), issuedAt: self::CLOCK);
        [$header, $payload] = explode('.', $compact);

        // assert
        fact((string) Base64Url::decode($payload))->notHasJsonPath('exp')->notHasJsonPath('ttl');
        fact((string) Base64Url::decode($header))->notHasJsonPath('kid');
    }

    public function testDefaultsIssuedAtToNow(): void
    {
        // arrange
        $before = time();

        // act
        $compact = (new StatusListTokenIssuer(self::localSigner()))->issue(self::LIST_URI, self::exampleList());
        $payload = json_decode((string) Base64Url::decode(explode('.', $compact)[1]), true);

        // assert
        fact($payload['iat'])->isGreaterThanOrEqual($before);
        fact($payload['iat'])->isLowerThanOrEqual(time());
    }

    public function testExtraClaimsAndHeadersAreCarriedButReservedOnesWin(): void
    {
        // act
        $compact = (new StatusListTokenIssuer(self::localSigner()))->issue(
            uri: self::LIST_URI,
            statusList: self::exampleList(),
            issuedAt: self::CLOCK,
            claims: ['iss' => 'https://example.com', 'sub' => 'spoofed', 'iat' => 1],
            header: ['x5u' => 'https://example.com/cert.pem', 'typ' => 'JWT'],
        );
        [$header, $payload] = explode('.', $compact);
        $decodedHeader = (string) Base64Url::decode($header);
        $decodedPayload = (string) Base64Url::decode($payload);

        // assert: extras are present
        fact($decodedPayload)->jsonPath('iss', 'https://example.com');
        fact($decodedHeader)->jsonPath('x5u', 'https://example.com/cert.pem');

        // assert: reserved members cannot be overridden
        fact($decodedPayload)->jsonPath('sub', self::LIST_URI)->jsonPath('iat', self::CLOCK);
        fact($decodedHeader)->jsonPath('typ', 'statuslist+jwt');
    }

    public function testInfersEdDsaForAnEd25519Signer(): void
    {
        // arrange
        $keyPair = sodium_crypto_sign_keypair();
        $issuer = new StatusListTokenIssuer(new Ed25519Signer(sodium_crypto_sign_secretkey($keyPair)));

        // act
        $compact = $issuer->issue(self::LIST_URI, self::exampleList(), issuedAt: self::CLOCK);
        $token = (new StatusListTokenVerifier(clock: self::CLOCK))
            ->verify($compact, new Ed25519Verifier(sodium_crypto_sign_publickey($keyPair)));

        // assert
        fact($issuer->algorithm())->is('EdDSA');
        fact($token->header()->alg)->is('EdDSA');
    }

    public function testRequiresAnExplicitAlgorithmWhenItCannotBeInferred(): void
    {
        // arrange
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        fact($resource)->notFalse();
        openssl_pkey_export($resource, $pem);
        $rsa = RsaSigner::fromPem((string) $pem);

        // act + assert: no guess for RSA — the hash is not visible from outside
        fact(static fn () => new StatusListTokenIssuer($rsa))
            ->throws(InvalidStatusListTokenException::class, 'Pass the JOSE algorithm explicitly');

        // assert: explicit is fine
        fact((new StatusListTokenIssuer($rsa, 'RS256'))->algorithm())->is('RS256');
    }

    public function testRejectsAnExpiryBeforeIssuance(): void
    {
        // arrange
        $issuer = new StatusListTokenIssuer(self::localSigner());

        // act + assert
        fact(static fn () => $issuer->issue(self::LIST_URI, self::exampleList(), issuedAt: 100, expiresAt: 100))
            ->throws(InvalidStatusListTokenException::class, 'expires after it is issued');
    }

    public function testRejectsANonPositiveTtl(): void
    {
        // arrange
        $issuer = new StatusListTokenIssuer(self::localSigner());

        // act + assert
        fact(static fn () => $issuer->issue(self::LIST_URI, self::exampleList(), ttl: 0))
            ->throws(InvalidStatusListTokenException::class, 'positive number of seconds');
    }
}

<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Tests\Support;

use K2gl\Dsse\EcdsaP256Signer;
use K2gl\Dsse\PublicKey;
use K2gl\Dsse\Verifier;
use K2gl\TokenStatusList\StatusList;
use K2gl\TokenStatusList\StatusListTokenIssuer;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

abstract class TokenStatusListTestCase extends TestCase
{
    protected const LIST_URI = 'https://example.com/statuslists/1';

    /** A moment inside the validity window of every token the tests issue. */
    protected const CLOCK = 1_700_000_000;

    /**
     * @return array{bits: int, lst: string, statuses: array<string, int>}
     */
    protected static function appendixVector(int $bits): array
    {
        $contents = file_get_contents(sprintf('%s/../fixtures/appendix-c/%d-bit.json', __DIR__, $bits));
        fact($contents)->notFalse();

        /** @var array{bits: int, lst: string, statuses: array<string, int>} */
        return json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
    }

    protected static function localSigner(?string $keyId = null): EcdsaP256Signer
    {
        return EcdsaP256Signer::fromPem(self::localKey()['private'], $keyId);
    }

    protected static function localVerifier(): Verifier
    {
        return PublicKey::fromPem(self::localKey()['public']);
    }

    /** A key that is not the local one, for "wrong key" scenarios. */
    protected static function strangerVerifier(): Verifier
    {
        return PublicKey::fromPem(self::generateKey()['public']);
    }

    /** The specification's Section 4.1 list: 16 one-bit statuses, `lst` = eNrbuRgAAhcBXQ. */
    protected static function exampleList(): StatusList
    {
        return StatusList::fromBytes("\xB9\xA3", bits: 1);
    }

    protected static function issueToken(
        ?StatusList $statusList = null,
        ?int $issuedAt = self::CLOCK - 3600,
        ?int $expiresAt = self::CLOCK + 86400,
        ?int $ttl = 43200,
        string $uri = self::LIST_URI,
    ): string {
        return (new StatusListTokenIssuer(self::localSigner()))->issue(
            uri: $uri,
            statusList: $statusList ?? self::exampleList(),
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            ttl: $ttl,
        );
    }

    /**
     * @return array{private: string, public: string}
     */
    protected static function localKey(): array
    {
        static $key = null;

        return $key ??= self::generateKey();
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function generateKey(): array
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        fact($resource)->notFalse();
        openssl_pkey_export($resource, $pem);
        $details = openssl_pkey_get_details($resource);
        fact($details)->notFalse();

        return ['private' => (string) $pem, 'public' => (string) $details['key']];
    }
}

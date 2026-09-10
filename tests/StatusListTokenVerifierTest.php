<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Tests;

use K2gl\Dsse\Verifier;
use K2gl\TokenStatusList\Exception\InvalidStatusListException;
use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use K2gl\TokenStatusList\Exception\TokenStatusListException;
use K2gl\TokenStatusList\Internal\Base64Url;
use K2gl\TokenStatusList\Internal\Json;
use K2gl\TokenStatusList\KeyResolver;
use K2gl\TokenStatusList\StatusListToken;
use K2gl\TokenStatusList\StatusListTokenVerifier;
use K2gl\TokenStatusList\StatusReference;
use K2gl\TokenStatusList\Tests\Support\TokenStatusListTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(StatusListTokenVerifier::class)]
#[CoversClass(StatusListToken::class)]
final class StatusListTokenVerifierTest extends TokenStatusListTestCase
{
    /** The Section 8.2 example response body (signed with a key the draft does not publish). */
    private const SPECIFICATION_EXAMPLE = 'eyJhbGciOiJFUzI1NiIsImtpZCI6IjEyIiwidHlwIjoic3RhdHVzbGlzdCtqd3QifQ.e'
        . 'yJleHAiOjIyOTE3MjAxNzAsImlhdCI6MTY4NjkyMDE3MCwiaXNzIjoiaHR0cHM6Ly9le'
        . 'GFtcGxlLmNvbSIsInN0YXR1c19saXN0Ijp7ImJpdHMiOjEsImxzdCI6ImVOcmJ1UmdBQ'
        . 'WhjQlhRIn0sInN1YiI6Imh0dHBzOi8vZXhhbXBsZS5jb20vc3RhdHVzbGlzdHMvMSIsI'
        . 'nR0bCI6NDMyMDB9.2lKUUNG503R9htu4aHAYi7vjmr3sgApbfoDvPrl65N3URUO1EYqq'
        . 'Ql45Jfzd-Av4QzlKa3oVALpLwOEUOq-U_g';

    public function testVerifiesAnIssuedToken(): void
    {
        // arrange
        $compact = self::issueToken();

        // act
        $token = self::verifier()->verify($compact, self::localVerifier(), expectedUri: self::LIST_URI);

        // assert: claims
        fact($token->subject())->is(self::LIST_URI);
        fact($token->issuedAt())->is(self::CLOCK - 3600);
        fact($token->expiresAt())->is(self::CLOCK + 86400);
        fact($token->ttl())->is(43200);
        fact($token->toCompact())->is($compact);
        fact($token->header()->typ)->is('statuslist+jwt');
        fact($token->claim('sub'))->is(self::LIST_URI);
        fact($token->claim('nope'))->null();
        fact($token->payload())->hasProperty('status_list');

        // assert: the list is the Section 4.1 example
        fact($token->statusList()->count())->is(16);
        fact($token->statusList()->get(0)->isInvalid())->true();
        fact($token->statusList()->get(1)->isValid())->true();
    }

    public function testAnswersTheStatusOfAReferencedToken(): void
    {
        // arrange
        $token = self::verifier()->verify(self::issueToken(), self::localVerifier());

        // act + assert
        fact($token->status(new StatusReference(self::LIST_URI, 0))->isInvalid())->true();
        fact($token->status(new StatusReference(self::LIST_URI, 2))->isValid())->true();
    }

    public function testRejectsAReferenceToAnotherList(): void
    {
        // arrange
        $token = self::verifier()->verify(self::issueToken(), self::localVerifier());
        $foreign = new StatusReference('https://example.com/statuslists/2', 0);

        // act + assert
        fact(static fn () => $token->status($foreign))
            ->throws(InvalidStatusListTokenException::class, 'does not match the referenced URI');
    }

    public function testRejectsAReferenceOutsideTheList(): void
    {
        // arrange
        $token = self::verifier()->verify(self::issueToken(), self::localVerifier());
        $beyond = new StatusReference(self::LIST_URI, 16);

        // act + assert
        fact(static fn () => $token->status($beyond))->throws(InvalidStatusListException::class, 'outside');
    }

    public function testComputesHowLongAFetchedCopyStaysFresh(): void
    {
        // arrange
        $withBoth = self::verifier()->verify(self::issueToken(), self::localVerifier());
        $ttlOnly = self::verifier()->verify(self::issueToken(expiresAt: null, ttl: 60), self::localVerifier());
        $expOnly = self::verifier()->verify(self::issueToken(ttl: null), self::localVerifier());
        $neither = self::verifier()->verify(self::issueToken(expiresAt: null, ttl: null), self::localVerifier());

        // act + assert: the earlier of fetch + ttl and exp
        fact($withBoth->freshUntil(self::CLOCK))->is(self::CLOCK + 43200);
        fact($withBoth->freshUntil(self::CLOCK + 80000))->is(self::CLOCK + 86400);
        fact($ttlOnly->freshUntil(self::CLOCK))->is(self::CLOCK + 60);
        fact($expOnly->freshUntil(self::CLOCK))->is(self::CLOCK + 86400);
        fact($neither->freshUntil(self::CLOCK))->null();
    }

    public function testResolvesTheKeyThroughAKeyResolver(): void
    {
        // arrange
        $seen = new stdClass;
        $resolver = new class ($seen, self::localVerifier()) implements KeyResolver {
            public function __construct(private readonly stdClass $seen, private readonly Verifier $verifier) {}

            public function resolve(stdClass $header, stdClass $payload): Verifier
            {
                $this->seen->kid = $header->kid;
                $this->seen->sub = $payload->sub;

                return $this->verifier;
            }
        };
        $compact = self::issueTokenWithKeyId('key-7');

        // act
        $token = self::verifier()->verify($compact, $resolver);

        // assert
        fact($token->subject())->is(self::LIST_URI);
        fact($seen->kid)->is('key-7');
        fact($seen->sub)->is(self::LIST_URI);
    }

    public function testAKeyResolverFailurePropagates(): void
    {
        // arrange
        $resolver = new class () implements KeyResolver {
            public function resolve(stdClass $header, stdClass $payload): Verifier
            {
                throw new TokenStatusListException('No trusted key for kid.');
            }
        };

        // act + assert
        fact(fn () => self::verifier()->verify(self::issueToken(), $resolver))
            ->throws(TokenStatusListException::class, 'No trusted key');
    }

    #[DataProvider('typeSpellings')]
    public function testAcceptsTheTypeCaseInsensitivelyWithOrWithoutMediaTypePrefix(string $typ): void
    {
        // arrange
        $compact = self::craft(['alg' => 'ES256', 'typ' => $typ], self::validPayload());

        // act + assert
        fact(self::verifier()->verify($compact, self::localVerifier())->header()->typ)->is($typ);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typeSpellings(): array
    {
        return [
            'canonical'   => ['statuslist+jwt'],
            'upper case'  => ['STATUSLIST+JWT'],
            'full media type' => ['application/statuslist+jwt'],
        ];
    }

    public function testAllowsClockSkewWithinTheLeeway(): void
    {
        // arrange: one token issued 30 s in the future, another expired 30 s ago
        $early = self::issueToken(issuedAt: self::CLOCK + 30);
        $late = self::issueToken(expiresAt: self::CLOCK - 30);
        $lenient = new StatusListTokenVerifier(clock: self::CLOCK, clockLeewaySeconds: 60);

        // act + assert: within 60 s of leeway both pass
        fact($lenient->verify($early, self::localVerifier())->issuedAt())->is(self::CLOCK + 30);
        fact($lenient->verify($late, self::localVerifier())->expiresAt())->is(self::CLOCK - 30);

        // assert: without leeway both fail
        fact(fn () => self::verifier()->verify($early, self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, 'issued in the future');
        fact(fn () => self::verifier()->verify($late, self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, 'has expired');
    }

    public function testUsesTheSystemClockByDefault(): void
    {
        // arrange
        $current = self::issueToken(issuedAt: time() - 10, expiresAt: time() + 600);
        $expired = self::issueToken(issuedAt: time() - 600, expiresAt: time() - 10);

        // act + assert
        fact((new StatusListTokenVerifier)->verify($current, self::localVerifier())->ttl())->is(43200);
        fact(static fn () => (new StatusListTokenVerifier)->verify($expired, self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, 'has expired');
    }

    public function testParsesTheSpecificationExampleUpToItsSignature(): void
    {
        // act + assert: header and claims pass; only the signature cannot be checked without the draft's key
        fact(fn () => self::verifier(clock: 1686920170)->verify(self::SPECIFICATION_EXAMPLE, self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, 'signature is invalid');
    }

    public function testRejectsASignatureByAnotherKey(): void
    {
        // act + assert
        fact(fn () => self::verifier()->verify(self::issueToken(), self::strangerVerifier()))
            ->throws(InvalidStatusListTokenException::class, 'signature is invalid');
    }

    public function testRejectsATamperedPayload(): void
    {
        // arrange
        [$header, $payload, $signature] = explode('.', self::issueToken());
        $tampered = $header . '.' . Base64Url::encode(Json::encode(self::validPayload() + ['iss' => 'x'])) . '.' . $signature;

        // act + assert
        fact(fn () => self::verifier()->verify($tampered, self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, 'signature is invalid');
    }

    public function testRejectsASubjectThatDoesNotMatchTheExpectedUri(): void
    {
        // act + assert
        fact(fn () => self::verifier()->verify(self::issueToken(), self::localVerifier(), expectedUri: 'https://example.com/statuslists/2'))
            ->throws(InvalidStatusListTokenException::class, 'does not match the referenced URI');
    }

    public function testRejectsAnAlgorithmOutsideTheAllowList(): void
    {
        // arrange
        $strict = new StatusListTokenVerifier(allowedAlgorithms: ['ES384'], clock: self::CLOCK);

        // act + assert
        fact(fn () => $strict->verify(self::issueToken(), self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, 'algorithm "ES256" is not allowed (allowed: ES384)');
    }

    #[DataProvider('invalidHeaders')]
    public function testRejectsAnInvalidHeader(array $header, string $message): void
    {
        // arrange
        $compact = self::craft($header, self::validPayload());

        // act + assert
        fact(fn () => self::verifier()->verify($compact, self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, $message);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidHeaders(): array
    {
        return [
            'typ missing'      => [['alg' => 'ES256'], '"typ" header must be "statuslist+jwt"'],
            'typ JWT'          => [['alg' => 'ES256', 'typ' => 'JWT'], '"typ" header must be "statuslist+jwt"'],
            'alg none'         => [['alg' => 'none', 'typ' => 'statuslist+jwt'], 'algorithm "none" is not allowed'],
            'alg missing'      => [['typ' => 'statuslist+jwt'], 'algorithm "" is not allowed'],
            'crit present'     => [['alg' => 'ES256', 'typ' => 'statuslist+jwt', 'crit' => ['b64']], 'critical header extension'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsAnInvalidPayload(array $payload, string $message): void
    {
        // arrange
        $compact = self::craft(['alg' => 'ES256', 'typ' => 'statuslist+jwt'], $payload);

        // act + assert
        fact(fn () => self::verifier()->verify($compact, self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, $message);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        $valid = self::validPayload();

        return [
            'sub missing'          => [array_diff_key($valid, ['sub' => 1]), 'no "sub" claim'],
            'sub empty'            => [['sub' => ''] + $valid, 'no "sub" claim'],
            'iat missing'          => [array_diff_key($valid, ['iat' => 1]), 'no integer "iat" claim'],
            'iat as string'        => [['iat' => '1'] + $valid, 'no integer "iat" claim'],
            'exp as string'        => [['exp' => 'never'] + $valid, '"exp" claim must be an integer'],
            'exp in the past'      => [['exp' => self::CLOCK - 1] + $valid, 'has expired'],
            'iat in the future'    => [['iat' => self::CLOCK + 1] + $valid, 'issued in the future'],
            'ttl zero'             => [['ttl' => 0] + $valid, '"ttl" claim must be a positive number'],
            'ttl negative'         => [['ttl' => -5] + $valid, '"ttl" claim must be a positive number'],
            'ttl as string'        => [['ttl' => '60'] + $valid, '"ttl" claim must be a positive number'],
            'status_list missing'  => [array_diff_key($valid, ['status_list' => 1]), 'no "status_list" claim'],
            'status_list as array' => [['status_list' => [1]] + $valid, 'no "status_list" claim'],
        ];
    }

    public function testAcceptsAFractionalTtl(): void
    {
        // arrange
        $compact = self::craft(['alg' => 'ES256', 'typ' => 'statuslist+jwt'], ['ttl' => 1.5] + self::validPayload());

        // act + assert
        fact(self::verifier()->verify($compact, self::localVerifier())->ttl())->is(1);
    }

    public function testAMalformedListInsideAValidTokenIsAListError(): void
    {
        // arrange
        $compact = self::craft(
            ['alg' => 'ES256', 'typ' => 'statuslist+jwt'],
            ['status_list' => ['bits' => 3, 'lst' => 'eNrbuRgAAhcBXQ']] + self::validPayload(),
        );

        // act + assert
        fact(fn () => self::verifier()->verify($compact, self::localVerifier()))
            ->throws(InvalidStatusListException::class, '1, 2, 4 or 8 bits');
    }

    #[DataProvider('malformedCompacts')]
    public function testRejectsAMalformedCompactSerialization(string $compact, string $message): void
    {
        // act + assert
        fact(fn () => self::verifier()->verify($compact, self::localVerifier()))
            ->throws(InvalidStatusListTokenException::class, $message);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedCompacts(): array
    {
        $valid = Base64Url::encode('{"alg":"ES256","typ":"statuslist+jwt"}');

        return [
            'two parts'          => ['a.b', 'expected three dot-separated parts'],
            'empty part'         => ['a..c', 'expected three dot-separated parts'],
            'header not base64'  => ['a+b.c.d', 'header is not base64url'],
            'header not JSON'    => [Base64Url::encode('nope') . '.c.d', 'Invalid JSON'],
            'header not object'  => [Base64Url::encode('[1]') . '.c.d', 'Expected a JSON object'],
            'payload not base64' => [$valid . '.p+p.d', 'payload is not base64url'],
            'signature not base64' => [$valid . '.' . Base64Url::encode('{}') . '.s+s', 'signature is not base64url'],
        ];
    }

    private static function verifier(int $clock = self::CLOCK): StatusListTokenVerifier
    {
        return new StatusListTokenVerifier(clock: $clock);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validPayload(): array
    {
        return [
            'sub' => self::LIST_URI,
            'iat' => self::CLOCK - 60,
            'exp' => self::CLOCK + 60,
            'status_list' => ['bits' => 1, 'lst' => 'eNrbuRgAAhcBXQ'],
        ];
    }

    /**
     * A compact JWS signed by the local key with exactly the given header and payload.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private static function craft(array $header, array $payload): string
    {
        $signingInput = Base64Url::encode(Json::encode($header)) . '.' . Base64Url::encode(Json::encode($payload));

        return $signingInput . '.' . Base64Url::encode(self::localSigner()->sign($signingInput));
    }

    private static function issueTokenWithKeyId(string $keyId): string
    {
        return self::craft(['alg' => 'ES256', 'typ' => 'statuslist+jwt', 'kid' => $keyId], self::validPayload());
    }
}

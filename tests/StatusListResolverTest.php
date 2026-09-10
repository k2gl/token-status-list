<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Tests;

use K2gl\TokenStatusList\Exception\InvalidStatusListException;
use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use K2gl\TokenStatusList\Exception\StatusListFetchFailed;
use K2gl\TokenStatusList\StatusListResolver;
use K2gl\TokenStatusList\StatusListTokenVerifier;
use K2gl\TokenStatusList\StatusReference;
use K2gl\TokenStatusList\Tests\Support\ArrayCache;
use K2gl\TokenStatusList\Tests\Support\FakeHttpClient;
use K2gl\TokenStatusList\Tests\Support\TokenStatusListTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(StatusListResolver::class)]
final class StatusListResolverTest extends TokenStatusListTestCase
{
    public function testChecksTheStatusOfAReferencedToken(): void
    {
        // arrange
        $http = new FakeHttpClient([self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken())]);
        $resolver = self::resolver($http);

        // act
        $revoked = $resolver->check(new StatusReference(self::LIST_URI, 0));
        $fine = $resolver->check(new StatusReference(self::LIST_URI, 1));

        // assert: statuses
        fact($revoked->isInvalid())->true();
        fact($fine->isValid())->true();

        // assert: the request negotiates the JWT media type
        fact($http->requests()[0]->getMethod())->is('GET');
        fact($http->requests()[0]->getHeaderLine('Accept'))->is('application/statuslist+jwt');
    }

    public function testFetchReturnsTheVerifiedToken(): void
    {
        // arrange
        $resolver = self::resolver(new FakeHttpClient([
            self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken(), 'application/statuslist+jwt; charset=utf-8'),
        ]));

        // act
        $token = $resolver->fetch(self::LIST_URI);

        // assert
        fact($token->subject())->is(self::LIST_URI);
        fact($token->statusList()->count())->is(16);
    }

    public function testFollowsRedirects(): void
    {
        // arrange
        $http = new FakeHttpClient([
            self::LIST_URI => new Response(302, ['Location' => 'https://cdn.example.com/statuslists/1']),
            'https://cdn.example.com/statuslists/1' => FakeHttpClient::statusListResponse(self::issueToken()),
        ]);

        // act
        $token = self::resolver($http)->fetch(self::LIST_URI);

        // assert: the token still names the original URI
        fact($token->subject())->is(self::LIST_URI);
        fact($http->requests())->count(2);
    }

    public function testGivesUpAfterTooManyRedirects(): void
    {
        // arrange
        $http = new FakeHttpClient([
            self::LIST_URI => new Response(301, ['Location' => 'https://example.com/a']),
            'https://example.com/a' => new Response(301, ['Location' => 'https://example.com/b']),
            'https://example.com/b' => new Response(301, ['Location' => 'https://example.com/c']),
        ]);
        $resolver = new StatusListResolver(
            httpClient: $http,
            requestFactory: new Psr17Factory,
            key: self::localVerifier(),
            verifier: new StatusListTokenVerifier(clock: self::CLOCK),
            maxRedirects: 2,
        );

        // act + assert
        fact(fn () => $resolver->fetch(self::LIST_URI))->throws(StatusListFetchFailed::class, 'exceeded 2 redirects');
    }

    public function testRejectsANonSuccessResponse(): void
    {
        // arrange
        $resolver = self::resolver(new FakeHttpClient([self::LIST_URI => new Response(404)]));

        // act + assert
        fact(fn () => $resolver->fetch(self::LIST_URI))->throws(StatusListFetchFailed::class, 'HTTP 404');
    }

    public function testRejectsAnUnexpectedContentType(): void
    {
        // arrange
        $resolver = self::resolver(new FakeHttpClient([
            self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken(), 'text/plain'),
        ]));

        // act + assert
        fact(fn () => $resolver->fetch(self::LIST_URI))
            ->throws(StatusListFetchFailed::class, 'Expected application/statuslist+jwt from https://example.com/statuslists/1, got "text/plain"');
    }

    public function testWrapsTransportFailures(): void
    {
        // arrange
        $resolver = self::resolver(new FakeHttpClient([self::LIST_URI => new RuntimeException('connection refused')]));

        // act + assert
        fact(fn () => $resolver->fetch(self::LIST_URI))->throws(StatusListFetchFailed::class, 'connection refused');
    }

    public function testRejectsATokenIssuedForAnotherUri(): void
    {
        // arrange: the host serves a token whose sub is a different list
        $resolver = self::resolver(new FakeHttpClient([
            self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken(uri: 'https://example.com/statuslists/2')),
        ]));

        // act + assert
        fact(fn () => $resolver->check(new StatusReference(self::LIST_URI, 0)))
            ->throws(InvalidStatusListTokenException::class, 'does not match the referenced URI');
    }

    public function testRejectsAnIndexOutsideTheList(): void
    {
        // arrange
        $resolver = self::resolver(new FakeHttpClient([self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken())]));

        // act + assert
        fact(fn () => $resolver->check(new StatusReference(self::LIST_URI, 16)))
            ->throws(InvalidStatusListException::class, 'Index 16 is outside');
    }

    public function testReusesACachedTokenWhileItIsFresh(): void
    {
        // arrange
        $http = new FakeHttpClient([self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken())]);
        $cache = new ArrayCache;
        $resolver = self::resolver($http, $cache);

        // act
        $resolver->check(new StatusReference(self::LIST_URI, 0));
        $resolver->check(new StatusReference(self::LIST_URI, 1));

        // assert: one fetch, one cache entry
        fact($http->requests())->count(1);
        fact($cache->values)->count(1);

        // assert: cached for ttl (43200 s), which is earlier than exp
        fact(array_values($cache->ttls)[0])->is(43200);
    }

    public function testCachesForTheRemainingLifetimeWhenExpiryComesFirst(): void
    {
        // arrange: no ttl, exp 600 s after the verifier clock
        $cache = new ArrayCache;
        $resolver = self::resolver(
            new FakeHttpClient([self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken(expiresAt: self::CLOCK + 600, ttl: null))]),
            $cache,
        );

        // act
        $resolver->fetch(self::LIST_URI);

        // assert
        fact(array_values($cache->ttls)[0])->is(600);
    }

    public function testDoesNotCacheATokenWithoutTtlOrExpiry(): void
    {
        // arrange
        $cache = new ArrayCache;
        $resolver = self::resolver(
            new FakeHttpClient([self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken(expiresAt: null, ttl: null))]),
            $cache,
        );

        // act
        $resolver->fetch(self::LIST_URI);

        // assert
        fact($cache->values)->isEmptyArray();
    }

    public function testRefetchesWhenTheCachedCopyDoesNotVerify(): void
    {
        // arrange: a cache entry signed by someone else
        $http = new FakeHttpClient([self::LIST_URI => FakeHttpClient::statusListResponse(self::issueToken())]);
        $cache = new ArrayCache;
        $cache->set('k2gl_tsl_' . hash('sha256', self::LIST_URI), self::tamperedToken());
        $resolver = self::resolver($http, $cache);

        // act
        $status = $resolver->check(new StatusReference(self::LIST_URI, 0));

        // assert: the fresh copy was fetched and replaced the bad entry
        fact($status->isInvalid())->true();
        fact($http->requests())->count(1);
        fact(array_values($cache->values)[0])->notContainsString('tampered');
    }

    public function testResolvesHistoricalStatus(): void
    {
        // arrange
        $http = new FakeHttpClient([
            self::LIST_URI . '?time=' . (self::CLOCK - 1800) => FakeHttpClient::statusListResponse(self::issueToken()),
        ]);
        $cache = new ArrayCache;

        // act
        $token = self::resolver($http, $cache)->fetch(self::LIST_URI, at: self::CLOCK - 1800);

        // assert: the query carried the timestamp, the cache was bypassed
        fact($token->subject())->is(self::LIST_URI);
        fact((string) $http->requests()[0]->getUri())->endsWith('?time=' . (self::CLOCK - 1800));
        fact($cache->values)->isEmptyArray();
    }

    public function testAppendsTheTimeToAnExistingQuery(): void
    {
        // arrange
        $uri = 'https://example.com/statuslists?id=1';
        $http = new FakeHttpClient([$uri . '&time=' . self::CLOCK => FakeHttpClient::statusListResponse(self::issueToken(uri: $uri))]);

        // act
        $token = self::resolver($http)->fetch($uri, at: self::CLOCK);

        // assert
        fact($token->subject())->is($uri);
    }

    public function testRejectsAHistoricalResponseThatWasNotValidAtThatTime(): void
    {
        // arrange: a static host ignores the query and serves a token issued after the requested time
        $requested = self::CLOCK - 7200;
        $http = new FakeHttpClient([self::LIST_URI . '?time=' . $requested => FakeHttpClient::statusListResponse(self::issueToken())]);

        // act + assert
        fact(fn () => self::resolver($http)->fetch(self::LIST_URI, at: $requested))
            ->throws(InvalidStatusListTokenException::class, sprintf('served for time %d was not valid then', $requested));
    }

    private static function resolver(FakeHttpClient $http, ?ArrayCache $cache = null): StatusListResolver
    {
        return new StatusListResolver(
            httpClient: $http,
            requestFactory: new Psr17Factory,
            key: self::localVerifier(),
            verifier: new StatusListTokenVerifier(clock: self::CLOCK),
            cache: $cache,
        );
    }

    /** The local token with its signature replaced, so it no longer verifies. */
    private static function tamperedToken(): string
    {
        [$header, $payload] = explode('.', self::issueToken());

        return $header . '.' . $payload . '.tampered';
    }
}

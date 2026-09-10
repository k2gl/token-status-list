<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList;

use K2gl\Dsse\Verifier;
use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use K2gl\TokenStatusList\Exception\StatusListFetchFailed;
use K2gl\TokenStatusList\Exception\TokenStatusListException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Fetches Status List Tokens over HTTP (Section 8.1–8.2) and answers the
 * question a Relying Party actually has: what is the status of this
 * Referenced Token (Section 8.3)?
 *
 * ```php
 * $resolver = new StatusListResolver($psr18Client, $psr17RequestFactory, $issuerKey, cache: $psr16Cache);
 * $status = $resolver->check(StatusReference::fromClaim($payload->status));
 * ```
 *
 * With a PSR-16 cache, fetched tokens are reused for `ttl` seconds (bounded
 * by `exp`) as Section 13.7 recommends; every cached copy is verified again
 * before use.
 */
final class StatusListResolver
{
    public const MEDIA_TYPE = 'application/statuslist+jwt';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly Verifier|KeyResolver $key,
        private readonly StatusListTokenVerifier $verifier = new StatusListTokenVerifier,
        private readonly ?CacheInterface $cache = null,
        private readonly int $maxRedirects = 3,
    ) {}

    /** Section 8.3 steps 2–7: resolve the referenced token and read the status at its index. */
    public function check(StatusReference $reference): Status
    {
        return $this->fetch($reference->uri)->status($reference);
    }

    /**
     * Fetch and verify the Status List Token at $uri.
     *
     * @param int|null $at unix time for historical resolution (Section 8.4):
     *     the token must have been valid at that moment; the cache is bypassed
     */
    public function fetch(string $uri, ?int $at = null): StatusListToken
    {
        if ($at !== null) {
            return $this->fetchAt($uri, $at);
        }

        $cached = $this->cachedToken($uri);

        if ($cached !== null) {
            return $cached;
        }

        $token = $this->verifier->verify($this->request($uri), $this->key, expectedUri: $uri);
        $this->remember($uri, $token);

        return $token;
    }

    private function fetchAt(string $uri, int $at): StatusListToken
    {
        $query = 'time=' . $at;
        $historicalUri = $uri . (str_contains($uri, '?') ? '&' : '?') . $query;
        $token = $this->verifier->verify($this->request($historicalUri), $this->key, expectedUri: $uri);

        // A static host ignores the query and serves the current list; the
        // response only counts if it was actually valid at the requested time.
        if ($token->issuedAt() > $at || ($token->expiresAt() !== null && $token->expiresAt() <= $at)) {
            throw new InvalidStatusListTokenException(sprintf(
                'The Status List Token served for time %d was not valid then (iat %d, exp %s).',
                $at,
                $token->issuedAt(),
                $token->expiresAt() ?? 'none',
            ));
        }

        return $token;
    }

    private function request(string $uri): string
    {
        $location = $uri;

        for ($hop = 0; ; $hop++) {
            $response = $this->send($location);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                break;
            }

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                if ($hop >= $this->maxRedirects) {
                    throw new StatusListFetchFailed(sprintf('Fetching %s exceeded %d redirects.', $uri, $this->maxRedirects));
                }

                $location = $response->getHeaderLine('Location');

                continue;
            }

            throw new StatusListFetchFailed(sprintf('Fetching %s failed: HTTP %d.', $location, $status));
        }

        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        if ($contentType !== self::MEDIA_TYPE) {
            throw new StatusListFetchFailed(sprintf(
                'Expected %s from %s, got "%s".',
                self::MEDIA_TYPE,
                $location,
                $response->getHeaderLine('Content-Type'),
            ));
        }

        return trim((string) $response->getBody());
    }

    private function send(string $uri): ResponseInterface
    {
        $request = $this->requestFactory->createRequest('GET', $uri)->withHeader('Accept', self::MEDIA_TYPE);

        try {
            return $this->httpClient->sendRequest($request);
        } catch (Throwable $e) {
            throw new StatusListFetchFailed(sprintf('Fetching %s failed: %s', $uri, $e->getMessage()), previous: $e);
        }
    }

    private function cachedToken(string $uri): ?StatusListToken
    {
        if ($this->cache === null) {
            return null;
        }

        $compact = $this->cache->get(self::cacheKey($uri));

        if (! is_string($compact)) {
            return null;
        }

        // The cache is storage, not a trust boundary: a stale or tampered copy
        // simply fails verification and triggers a fresh fetch.
        try {
            return $this->verifier->verify($compact, $this->key, expectedUri: $uri);
        } catch (TokenStatusListException) {
            return null;
        }
    }

    private function remember(string $uri, StatusListToken $token): void
    {
        if ($this->cache === null) {
            return;
        }

        $now = $this->verifier->now();
        $freshUntil = $token->freshUntil($now);

        if ($freshUntil === null || $freshUntil <= $now) {
            return;
        }

        $this->cache->set(self::cacheKey($uri), $token->toCompact(), $freshUntil - $now);
    }

    private static function cacheKey(string $uri): string
    {
        return 'k2gl_tsl_' . hash('sha256', $uri);
    }
}

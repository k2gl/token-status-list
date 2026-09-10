# k2gl/token-status-list

[![CI](https://img.shields.io/github/actions/workflow/status/k2gl/token-status-list/ci.yml?branch=main&label=CI&logo=github)](https://github.com/k2gl/token-status-list/actions)
[![Latest Stable Version](https://img.shields.io/packagist/v/k2gl/token-status-list)](https://packagist.org/packages/k2gl/token-status-list)
[![Total Downloads](https://img.shields.io/packagist/dt/k2gl/token-status-list)](https://packagist.org/packages/k2gl/token-status-list)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-2a5ea7)](https://phpstan.org/)
[![License](https://img.shields.io/packagist/l/k2gl/token-status-list)](https://packagist.org/packages/k2gl/token-status-list)

Token Status List
([draft-ietf-oauth-status-list](https://datatracker.ietf.org/doc/draft-ietf-oauth-status-list/))
in pure PHP: the revocation mechanism behind SD-JWT VC and the EU Digital Identity Wallet.
An issuer publishes one signed, compressed bit array for many tokens; each token carries a
`status` claim pointing at that list and an index into it. A relying party fetches the list
once, verifies it, and reads a couple of bits.

Both sides are covered: build and sign a Status List Token, and resolve and check the status
of a Referenced Token. Tracks **draft -21**. The test suite reproduces the draft's Appendix C
test vectors byte for byte — the 1-, 2-, 4- and 8-bit lists both decode to the listed
statuses and re-encode to the exact `lst` values.

## Install

```bash
composer require k2gl/token-status-list
```

Requires PHP 8.1+ with `ext-zlib`. Signatures come from
[k2gl/dsse](https://github.com/k2gl/dsse) (ECDSA P-256/384/521, Ed25519, RSA). Fetching
speaks PSR-18/PSR-17, so bring any HTTP client; caching is optional and PSR-16.

## Usage

### Check the status of a token (Relying Party)

```php
use K2gl\Dsse\PublicKey;
use K2gl\TokenStatusList\StatusListResolver;
use K2gl\TokenStatusList\StatusReference;

$resolver = new StatusListResolver(
    httpClient: $psr18Client,
    requestFactory: $psr17RequestFactory,
    key: PublicKey::fromPem($issuerPublicKeyPem),
    cache: $psr16Cache, // optional
);

// $payload is the verified Referenced Token's claims — e.g. VerifiedSdJwtVc::status()
$reference = StatusReference::fromClaim($payload->status);

$status = $resolver->check($reference);

$status->isValid();      // 0x00
$status->isInvalid();    // 0x01 — revoked
$status->isSuspended();  // 0x02
$status->value;          // the raw value, for application-specific statuses
```

`check()` is Section 8.3 end to end: GET the URI with `Accept: application/statuslist+jwt`
(following redirects), verify the token, require `sub` to equal the referenced URI, and
read the index — an index beyond the list is rejected, not reported as valid. With a
PSR-16 cache the token is reused for `ttl` seconds, bounded by `exp`, and every cached copy
is verified again before use.

Validate the Referenced Token itself first (signature, `exp`); the specification is explicit
that an expired token with a `VALID` status is still expired.

The key is a k2gl/dsse `Verifier`, or a `KeyResolver` when the key depends on the token —
`kid` against a trusted JWKS, `x5c` against your trust anchors. Key discovery is ecosystem
specific, so the package defines the seam and nothing more:

```php
use K2gl\TokenStatusList\KeyResolver;

final class IssuerKeys implements KeyResolver
{
    public function resolve(stdClass $header, stdClass $payload): Verifier
    {
        return $this->jwks->find($header->kid ?? null) ?? throw new TokenStatusListException('Unknown kid.');
    }
}
```

Historical status (Section 8.4): `$resolver->fetch($uri, at: $timestamp)` sends
`?time=…` and rejects a response that was not valid at that moment — a static host that
ignores the query does not pass off the current list as an old one.

### Publish a Status List (Status Issuer)

```php
use K2gl\Dsse\EcdsaP256Signer;
use K2gl\TokenStatusList\Status;
use K2gl\TokenStatusList\StatusList;
use K2gl\TokenStatusList\StatusListTokenIssuer;

$list = StatusList::create(size: 100_000, bits: 1);
$list->set(42, Status::invalid());

$issuer = new StatusListTokenIssuer(EcdsaP256Signer::fromPem($privateKeyPem, keyId: '12'));

$compact = $issuer->issue(
    uri: 'https://example.com/statuslists/1',
    statusList: $list,
    expiresAt: time() + 7 * 86400,
    ttl: 43200,
);
// serve $compact as application/statuslist+jwt at that URI
```

Use `bits: 2` when you need `SUSPENDED`, `4` or `8` for application-specific values. The
`alg` header is inferred for the ECDSA and Ed25519 signers of k2gl/dsse; pass it explicitly
for RSA (`new StatusListTokenIssuer($rsaSigner, 'RS256')`) or a KMS-backed signer.

The claim to put into each token you issue:

```php
use K2gl\TokenStatusList\StatusReference;

$claims['status'] = (new StatusReference('https://example.com/statuslists/1', index: 42))->toClaim();
// {"status_list": {"idx": 42, "uri": "https://example.com/statuslists/1"}}
```

### Verify a token you already have

```php
use K2gl\TokenStatusList\StatusListTokenVerifier;

$verifier = new StatusListTokenVerifier(
    allowedAlgorithms: ['ES256'],  // default: ES256/384/512, EdDSA, RS256/384/512
    clockLeewaySeconds: 60,
);

$token = $verifier->verify($compact, $issuerKey, expectedUri: $reference->uri);

$token->status($reference);   // Status
$token->statusList();         // StatusList — get(), count(), bits()
$token->freshUntil(time());   // when to fetch again, from ttl and exp
```

Rejected, fail-closed: a `typ` other than `statuslist+jwt`, an `alg` outside the allow list
(`none` included), a `crit` header, a bad signature, a missing `sub`/`iat`/`status_list`, a
non-positive `ttl`, a token issued in the future or already expired, an `lst` that is not one
complete ZLIB stream, and a list that inflates beyond a size limit (16 MiB by default —
`gzuncompress()` accepts a limit but does not enforce it, so inflation is bounded here).

## Scope

- Status List encoding (Section 4.1–4.2) and JWT-format Status List Tokens (Section 5.1,
  Section 8.3 validation rules), issue and verify.
- Fetching over PSR-18 with content negotiation, redirects, historical resolution, and
  PSR-16 caching per the `ttl`/`exp` guidance of Section 13.7.
- `aggregation_uri` is surfaced on the list; walking a Status List Aggregation (Section 9)
  is left to the application.
- CWT/CBOR representations (Section 4.3, 5.2, 6.3) are not implemented — no CBOR
  dependency; the JOSE side is what SD-JWT VC deployments use.
- MAC-protected tokens (Section 11.6) are not supported; asymmetric signatures only.

## License

MIT © [Nick Harin](https://github.com/k2gl)

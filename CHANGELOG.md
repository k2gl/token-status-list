# Changelog

## 1.0.0

- Initial release: Token Status List per draft-ietf-oauth-status-list-21.
- `StatusList` — 1/2/4/8-bit status arrays with the Section 4.1 bit layout, ZLIB `lst`
  encoding, and bounded inflation.
- `StatusListTokenIssuer` / `StatusListTokenVerifier` — Status List Tokens in JWT format,
  signed through k2gl/dsse; fail-closed validation of `typ`, `alg`, signature, claims and
  the time window.
- `StatusListResolver` — PSR-18 fetching with content negotiation and redirects, the
  Section 8.3 status check, historical resolution, and optional PSR-16 caching.
- `StatusReference` — the `status` claim of a Referenced Token, both ways.
- Test vectors: the draft's Section 4.1 examples and the Appendix C 1/2/4/8-bit lists,
  reproduced byte for byte.

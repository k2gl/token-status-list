<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Internal;

use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use stdClass;

/**
 * A compact JWS split into its three base64url parts, with lazily decoded
 * header and payload.
 *
 * @internal
 */
final class JwtParts
{
    private function __construct(
        public readonly string $encodedHeader,
        public readonly string $encodedPayload,
        public readonly string $encodedSignature,
    ) {}

    public static function parse(string $compact): self
    {
        $parts = explode('.', $compact);

        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new InvalidStatusListTokenException('Malformed JWT: expected three dot-separated parts.');
        }

        return new self($parts[0], $parts[1], $parts[2]);
    }

    public function header(): stdClass
    {
        return Json::decodeObject(self::decode($this->encodedHeader, 'header'));
    }

    public function payload(): stdClass
    {
        return Json::decodeObject(self::decode($this->encodedPayload, 'payload'));
    }

    public function signature(): string
    {
        return self::decode($this->encodedSignature, 'signature');
    }

    /** The bytes the signature is computed over. */
    public function signingInput(): string
    {
        return $this->encodedHeader . '.' . $this->encodedPayload;
    }

    private static function decode(string $encoded, string $part): string
    {
        $decoded = Base64Url::decode($encoded);

        if ($decoded === null) {
            throw new InvalidStatusListTokenException(sprintf('Malformed JWT: the %s is not base64url.', $part));
        }

        return $decoded;
    }
}

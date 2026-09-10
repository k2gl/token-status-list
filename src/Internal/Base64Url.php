<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Internal;

/**
 * Base64url without padding (RFC 4648 Section 5), as used throughout JOSE.
 *
 * @internal
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Returns null for anything that is not canonical base64url. */
    public static function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}

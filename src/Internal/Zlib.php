<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Internal;

/**
 * Bounded ZLIB (RFC 1950) inflation. `gzuncompress()` takes a length limit
 * but does not enforce it, so the stream is fed in small chunks and the
 * output measured as it grows — a decompression bomb is cut off after at
 * most one chunk's worth of expansion.
 *
 * @internal
 */
final class Zlib
{
    private const CHUNK_BYTES = 4096;

    /** Null when the data is not one complete ZLIB stream or inflates beyond $maxBytes. */
    public static function inflate(string $compressed, int $maxBytes): ?string
    {
        if ($compressed === '') {
            return null;
        }

        $context = inflate_init(ZLIB_ENCODING_DEFLATE);

        if ($context === false) {
            return null;
        }

        $inflated = '';

        foreach (str_split($compressed, self::CHUNK_BYTES) as $chunk) {
            $part = @inflate_add($context, $chunk);

            if ($part === false) {
                return null;
            }

            $inflated .= $part;

            if (strlen($inflated) > $maxBytes) {
                return null;
            }
        }

        if (inflate_get_status($context) !== ZLIB_STREAM_END || inflate_get_read_len($context) !== strlen($compressed)) {
            return null;
        }

        return $inflated;
    }
}

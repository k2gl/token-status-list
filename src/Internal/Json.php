<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Internal;

use JsonException;
use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use stdClass;

/**
 * JSON helpers that keep the object/array distinction: JSON objects decode to
 * {@see stdClass}, JSON arrays to PHP lists.
 *
 * @internal
 */
final class Json
{
    public static function decodeObject(string $json): stdClass
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidStatusListTokenException('Invalid JSON: ' . $e->getMessage(), previous: $e);
        }

        if (! $decoded instanceof stdClass) {
            throw new InvalidStatusListTokenException('Expected a JSON object.');
        }

        return $decoded;
    }

    /**
     * Compact encoding with unescaped slashes and Unicode, matching common
     * JOSE practice.
     */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidStatusListTokenException('Unable to encode JSON: ' . $e->getMessage(), previous: $e);
        }
    }
}

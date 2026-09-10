<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList;

use K2gl\TokenStatusList\Exception\InvalidStatusListException;
use stdClass;

/**
 * Where a Referenced Token's status lives (Section 6): the URI of the Status
 * List Token and the index within its list. Carried in the token's `status`
 * claim as `{"status_list": {"idx": …, "uri": …}}`.
 */
final class StatusReference
{
    public function __construct(
        public readonly string $uri,
        public readonly int $index,
    ) {
        if ($index < 0) {
            throw new InvalidStatusListException(sprintf('A status index is non-negative, %d given.', $index));
        }

        // RFC 3986: a URI starts with a scheme. Anything stricter would reject
        // legitimate non-HTTP identifiers that ecosystems may resolve out of band.
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\S+$/', $uri) !== 1) {
            throw new InvalidStatusListException(sprintf('"%s" is not a URI.', $uri));
        }
    }

    /**
     * From the value of a Referenced Token's `status` claim (Section 6.2).
     *
     * @param array<string, mixed>|stdClass $status
     */
    public static function fromClaim(array|stdClass $status): self
    {
        $members = $status instanceof stdClass ? get_object_vars($status) : $status;
        $statusList = $members['status_list'] ?? null;

        if ($statusList instanceof stdClass) {
            $statusList = get_object_vars($statusList);
        }

        if (! is_array($statusList)) {
            throw new InvalidStatusListException('The "status" claim has no "status_list" object.');
        }

        $index = $statusList['idx'] ?? null;
        $uri = $statusList['uri'] ?? null;

        if (! is_int($index)) {
            throw new InvalidStatusListException('The "status_list" reference must carry an integer "idx".');
        }

        if (! is_string($uri)) {
            throw new InvalidStatusListException('The "status_list" reference must carry a string "uri".');
        }

        return new self(uri: $uri, index: $index);
    }

    /**
     * The `status` claim to embed into a Referenced Token.
     *
     * @return array{status_list: array{idx: int, uri: string}}
     */
    public function toClaim(): array
    {
        return ['status_list' => ['idx' => $this->index, 'uri' => $this->uri]];
    }
}

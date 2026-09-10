<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList;

use Countable;
use K2gl\TokenStatusList\Exception\InvalidStatusListException;
use K2gl\TokenStatusList\Internal\Base64Url;
use K2gl\TokenStatusList\Internal\Zlib;
use stdClass;

/**
 * A Status List (Section 4): the statuses of many Referenced Tokens packed
 * into a byte array at 1, 2, 4 or 8 bits each, from the least significant bit
 * of byte 0 upwards. `lst` is that array DEFLATE-compressed in the ZLIB
 * format and base64url-encoded.
 *
 * The list is mutable on purpose — a Status Issuer flips individual entries
 * of a list holding a million statuses; copying it on every change would be
 * wasteful for no gain.
 */
final class StatusList implements Countable
{
    public const BITS = [1, 2, 4, 8];

    /**
     * Upper bound for a decompressed list (16 MiB, i.e. 128M one-bit
     * statuses); anything larger is treated as a decompression bomb.
     */
    public const DEFAULT_MAX_BYTES = 16 * 1024 * 1024;

    private function __construct(
        private readonly int $bits,
        private string $bytes,
        private readonly ?string $aggregationUri,
    ) {}

    /** A list of $size statuses, all VALID. */
    public static function create(int $size, int $bits = 1, ?string $aggregationUri = null): self
    {
        self::assertBits($bits);

        if ($size < 0) {
            throw new InvalidStatusListException(sprintf('A Status List cannot hold %d statuses.', $size));
        }

        return new self(
            bits: $bits,
            bytes: str_repeat("\0", intdiv($size * $bits + 7, 8)),
            aggregationUri: $aggregationUri,
        );
    }

    /** From an uncompressed byte array laid out as in Section 4.1. */
    public static function fromBytes(string $bytes, int $bits, ?string $aggregationUri = null): self
    {
        self::assertBits($bits);

        return new self(bits: $bits, bytes: $bytes, aggregationUri: $aggregationUri);
    }

    /** Decode an `lst` value: base64url of the ZLIB-compressed byte array. */
    public static function decode(
        string $lst,
        int $bits,
        ?string $aggregationUri = null,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): self {
        self::assertBits($bits);
        $compressed = Base64Url::decode($lst);

        if ($compressed === null) {
            throw new InvalidStatusListException('The Status List "lst" is not base64url.');
        }

        $bytes = Zlib::inflate($compressed, $maxBytes);

        if ($bytes === null) {
            throw new InvalidStatusListException(
                sprintf('The Status List "lst" is not ZLIB data or decompresses to more than %d bytes.', $maxBytes),
            );
        }

        return new self(bits: $bits, bytes: $bytes, aggregationUri: $aggregationUri);
    }

    /**
     * From the JSON `status_list` structure of Section 4.2 (`bits`, `lst`,
     * optional `aggregation_uri`), as found in a Status List Token.
     *
     * @param array<string, mixed>|stdClass $statusList
     */
    public static function fromArray(array|stdClass $statusList, int $maxBytes = self::DEFAULT_MAX_BYTES): self
    {
        $members = $statusList instanceof stdClass ? get_object_vars($statusList) : $statusList;
        $bits = $members['bits'] ?? null;
        $lst = $members['lst'] ?? null;
        $aggregationUri = $members['aggregation_uri'] ?? null;

        if (! is_int($bits)) {
            throw new InvalidStatusListException('The Status List "bits" member must be an integer.');
        }

        if (! is_string($lst)) {
            throw new InvalidStatusListException('The Status List "lst" member must be a string.');
        }

        if ($aggregationUri !== null && ! is_string($aggregationUri)) {
            throw new InvalidStatusListException('The Status List "aggregation_uri" member must be a string.');
        }

        return self::decode(
            lst: $lst,
            bits: $bits,
            aggregationUri: $aggregationUri,
            maxBytes: $maxBytes,
        );
    }

    public function bits(): int
    {
        return $this->bits;
    }

    /** The number of statuses the list holds. */
    public function count(): int
    {
        return match ($this->bits) {
            8 => strlen($this->bytes),
            4 => strlen($this->bytes) * 2,
            2 => strlen($this->bytes) * 4,
            default => strlen($this->bytes) * 8,
        };
    }

    public function aggregationUri(): ?string
    {
        return $this->aggregationUri;
    }

    public function get(int $index): Status
    {
        [$byte, $shift] = $this->locate($index);

        return Status::of((ord($this->bytes[$byte]) >> $shift) & $this->mask());
    }

    public function set(int $index, Status|int $status): void
    {
        $value = $status instanceof Status ? $status->value : Status::of($status)->value;
        $mask = $this->mask();

        if ($value > $mask) {
            throw new InvalidStatusListException(
                sprintf('Status value %d does not fit into %d bit(s).', $value, $this->bits),
            );
        }

        [$byte, $shift] = $this->locate($index);
        $updated = (ord($this->bytes[$byte]) & ~($mask << $shift)) | ($value << $shift);
        $this->bytes[$byte] = chr($updated & 0xFF);
    }

    /** The uncompressed byte array. */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /** The `lst` value: ZLIB-compressed at the highest level, base64url-encoded. */
    public function encode(): string
    {
        $compressed = gzcompress($this->bytes, 9);

        if ($compressed === false) {
            throw new InvalidStatusListException('Compressing the Status List failed.');
        }

        return Base64Url::encode($compressed);
    }

    /**
     * The JSON `status_list` structure of Section 4.2.
     *
     * @return array{bits: int, lst: string, aggregation_uri?: string}
     */
    public function toArray(): array
    {
        $statusList = ['bits' => $this->bits, 'lst' => $this->encode()];

        if ($this->aggregationUri !== null) {
            $statusList['aggregation_uri'] = $this->aggregationUri;
        }

        return $statusList;
    }

    public function withAggregationUri(?string $aggregationUri): self
    {
        return new self(bits: $this->bits, bytes: $this->bytes, aggregationUri: $aggregationUri);
    }

    /**
     * @return array{int, int} byte offset and bit shift of the status at $index
     */
    private function locate(int $index): array
    {
        if ($index < 0 || $index >= $this->count()) {
            throw new InvalidStatusListException(
                sprintf('Index %d is outside a Status List of %d statuses.', $index, $this->count()),
            );
        }

        $position = $index * $this->bits;

        return [$position >> 3, $position & 7];
    }

    private function mask(): int
    {
        return (1 << $this->bits) - 1;
    }

    private static function assertBits(int $bits): void
    {
        if (! in_array($bits, self::BITS, true)) {
            throw new InvalidStatusListException(sprintf('A Status List uses 1, 2, 4 or 8 bits per status, %d given.', $bits));
        }
    }
}

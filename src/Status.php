<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList;

use K2gl\TokenStatusList\Exception\InvalidStatusListException;

/**
 * The status of one Referenced Token: a value from 0 to 255 (Section 7).
 * Three values are registered by the specification; 0x03 and 0x0C–0x0F are
 * permanently application specific, the rest is reserved.
 */
final class Status
{
    /** The Referenced Token is valid, correct or legal. */
    public const VALID = 0x00;

    /** The Referenced Token is revoked, annulled, taken back, recalled or cancelled. */
    public const INVALID = 0x01;

    /** The Referenced Token is temporarily invalid, hanging, debarred from privilege. */
    public const SUSPENDED = 0x02;

    private const NAMES = [
        self::VALID => 'VALID',
        self::INVALID => 'INVALID',
        self::SUSPENDED => 'SUSPENDED',
    ];

    private function __construct(public readonly int $value) {}

    public static function of(int $value): self
    {
        if ($value < 0 || $value > 0xFF) {
            throw new InvalidStatusListException(sprintf('Status values range from 0 to 255, %d given.', $value));
        }

        return new self($value);
    }

    public static function valid(): self
    {
        return new self(self::VALID);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function suspended(): self
    {
        return new self(self::SUSPENDED);
    }

    public function isValid(): bool
    {
        return $this->value === self::VALID;
    }

    public function isInvalid(): bool
    {
        return $this->value === self::INVALID;
    }

    public function isSuspended(): bool
    {
        return $this->value === self::SUSPENDED;
    }

    /** 0x03 and 0x0C–0x0F: the meaning is defined by the application, not the specification. */
    public function isApplicationSpecific(): bool
    {
        return $this->value === 0x03 || ($this->value >= 0x0C && $this->value <= 0x0F);
    }

    /** The registered name (VALID, INVALID, SUSPENDED), or null for any other value. */
    public function name(): ?string
    {
        return self::NAMES[$this->value] ?? null;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList;

use K2gl\TokenStatusList\Exception\InvalidStatusListTokenException;
use stdClass;

/**
 * A verified Status List Token (Section 5.1): its claims and the Status List
 * it carries. Produced by {@see StatusListTokenVerifier}.
 */
final class StatusListToken
{
    /**
     * @internal constructed by the verifier after every check has passed
     */
    public function __construct(
        private readonly string $compact,
        private readonly stdClass $header,
        private readonly stdClass $payload,
        private readonly string $subject,
        private readonly int $issuedAt,
        private readonly ?int $expiresAt,
        private readonly ?int $ttl,
        private readonly StatusList $statusList,
    ) {}

    /** The compact JWS this token was verified from. */
    public function toCompact(): string
    {
        return $this->compact;
    }

    /** The `sub` claim: the URI of this Status List Token. */
    public function subject(): string
    {
        return $this->subject;
    }

    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    /** Seconds a fetched copy may be cached before a fresh one should be retrieved. */
    public function ttl(): ?int
    {
        return $this->ttl;
    }

    public function statusList(): StatusList
    {
        return $this->statusList;
    }

    public function header(): stdClass
    {
        return $this->header;
    }

    public function payload(): stdClass
    {
        return $this->payload;
    }

    public function claim(string $name): mixed
    {
        return $this->payload->{$name} ?? null;
    }

    /**
     * Until when a copy fetched at $fetchedAt may be reused (Section 13.7):
     * the earlier of `fetchedAt + ttl` and `exp`; null when the token carries
     * neither.
     */
    public function freshUntil(int $fetchedAt): ?int
    {
        $candidates = array_filter([
            $this->ttl === null ? null : $fetchedAt + $this->ttl,
            $this->expiresAt,
        ], static fn (?int $time): bool => $time !== null);

        return $candidates === [] ? null : min($candidates);
    }

    /**
     * The status this token asserts for a Referenced Token: the reference
     * must point at this token (Section 8.3 step 4a) and inside its list
     * (step 6).
     */
    public function status(StatusReference $reference): Status
    {
        if ($reference->uri !== $this->subject) {
            throw new InvalidStatusListTokenException(sprintf(
                'The Status List Token subject "%s" does not match the referenced URI "%s".',
                $this->subject,
                $reference->uri,
            ));
        }

        return $this->statusList->get($reference->index);
    }
}

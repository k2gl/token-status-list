<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList;

use K2gl\Dsse\Verifier;
use stdClass;

/**
 * Finds the key that a Status List Token must verify under. Key resolution
 * is ecosystem specific (Section 11.3) — `kid` against a trusted JWKS, `x5c`
 * against trust anchors, a key pinned per issuer — so the package only
 * defines the seam; a bare {@see Verifier} suffices when there is one key.
 */
interface KeyResolver
{
    /**
     * @throws \K2gl\TokenStatusList\Exception\TokenStatusListException when no trusted key applies
     */
    public function resolve(stdClass $header, stdClass $payload): Verifier;
}

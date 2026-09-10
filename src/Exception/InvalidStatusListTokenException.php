<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Exception;

/**
 * A Status List Token failed validation (Section 5.1 and 8.3): malformed
 * JWT, wrong `typ`, disallowed algorithm, bad signature, missing claims,
 * expired, or a subject that does not match the reference.
 */
final class InvalidStatusListTokenException extends TokenStatusListException {}

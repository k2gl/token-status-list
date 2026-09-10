<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Exception;

/**
 * A Status List, a status value or a status reference violates
 * draft-ietf-oauth-status-list: unsupported bits, an index outside the list,
 * a value that does not fit, or an `lst` that does not decode.
 */
final class InvalidStatusListException extends TokenStatusListException {}

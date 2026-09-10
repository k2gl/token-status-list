<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Exception;

/**
 * The Status List Token could not be retrieved from its URI (Section 8.1
 * and 8.2): transport error, non-2xx response, too many redirects, or an
 * unexpected content type.
 */
final class StatusListFetchFailed extends TokenStatusListException {}

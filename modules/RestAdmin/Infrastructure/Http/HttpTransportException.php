<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Http;

/**
 * HttpTransportException — the one failure type of the transport seam.
 *
 * Deliberately one type rather than a hierarchy: every caller in this module
 * does the same thing with a failure (show the operator the reason and stop),
 * and the panel has no use for telling a DNS refusal from a timeout beyond the
 * message itself. Subclasses can arrive when a caller needs to branch.
 *
 * The message never contains a credential: `SsrfGuard` names the host, and
 * `CurlTransport` names scheme, host, path and curl's own error — never the
 * query, which some APIs use for keys.
 */
final class HttpTransportException extends \RuntimeException
{
}

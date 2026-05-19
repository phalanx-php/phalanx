<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Transport;

/**
 * Raised when a transport adapter encounters a non-HTTP error: connection
 * failure, protocol violation, or other adapter-specific issue. Callers
 * that handle transport errors must not depend on
 * {@see \Phalanx\Iris\HttpClientException} or any other adapter-specific
 * exception type — catch this type instead to stay transport-agnostic.
 *
 * HTTP-level errors (non-2xx responses) are reported separately via
 * {@see \Phalanx\Panoply\Transport\Sync\HttpError}, which carries the
 * status code and response body.
 *
 * Final — sealed sentinel exception; the panoply error taxonomy depends
 * on the two-type split (HttpError vs TransportException).
 */
final class TransportException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation;

/**
 * Parser behaviour when an unrecognised record kind is encountered in a
 * conversation source.
 *
 * Loud — the default — throws immediately so callers know the parser
 * encountered something it was not designed for. Lenient yields a
 * Record\Unknown so consumers can inspect the raw payload. Silent drops the
 * record entirely, which is appropriate for production deployments that need
 * forward compatibility without log noise.
 */
enum StrictMode: string
{
    case Loud = 'loud';
    case Lenient = 'lenient';
    case Silent = 'silent';
}

<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Widget;

/**
 * Signals a Ctrl+U revert from inside a prompt. Form catches it to rewind
 * to the previous step; raised at index 0 it propagates out as the
 * caller's signal that the entire form was abandoned.
 */
final class FormRevertedException extends \RuntimeException
{
}

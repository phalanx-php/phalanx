<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Transport\Fake;

/**
 * Thrown when {@see Transport::stream()} receives a request whose
 * signature is not present in the fake's script map. Fail-loud by
 * default — add an explicit script entry for every request the test
 * exercises.
 */
final class UnscriptedRequest extends \RuntimeException
{
}

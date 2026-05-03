<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;

final readonly class StoaResponseWriter
{
    private static function targetIsWritable(Response $target): bool
    {
        $method = new ReflectionMethod($target, 'isWritable');

        return $method->invoke($target) === true;
    }

    private static function targetIsWritableAfterHeaders(Response $target): bool
    {
        $method = new ReflectionMethod($target, 'isWritable');

        return $method->invoke($target) === true;
    }

    public function write(ResponseInterface $source, Response $target, StoaRequestResource $request): void
    {
        if (!self::targetIsWritable($target)) {
            $request->abort('response is not writable before headers');
            throw new ResponseWriteFailure('OpenSwoole response is not writable before headers.');
        }

        if (!$target->status($source->getStatusCode(), $source->getReasonPhrase())) {
            throw new ResponseWriteFailure('OpenSwoole failed to set response status.');
        }

        $request->headersStarted();

        foreach ($source->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                if (!$target->header($name, $value)) {
                    throw new ResponseWriteFailure("OpenSwoole failed to write response header '{$name}'.");
                }
            }
        }

        if (!self::targetIsWritableAfterHeaders($target)) {
            $request->abort('response closed before body');
            throw new ResponseWriteFailure('OpenSwoole response closed before body.');
        }

        $request->bodyStarted();

        if (!$target->end((string) $source->getBody())) {
            throw new ResponseWriteFailure('OpenSwoole failed to finish response body.');
        }
    }
}

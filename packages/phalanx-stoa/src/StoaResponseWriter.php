<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;

final readonly class StoaResponseWriter
{
    public function write(ResponseInterface $source, Response $target, StoaRequestResource $request): void
    {
        if (!$target->isWritable()) {
            $request->abort('response is not writable before headers');
            throw new ResponseWriteFailure('OpenSwoole response is not writable before headers.');
        }

        if (!$target->status($source->getStatusCode(), $source->getReasonPhrase())) {
            $failure = new ResponseWriteFailure('OpenSwoole failed to set response status.');
            $request->fail($failure);
            throw $failure;
        }

        $request->headersStarted();

        foreach ($source->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                if (!$target->header($name, $value)) {
                    $failure = new ResponseWriteFailure("OpenSwoole failed to write response header '{$name}'.");
                    $request->fail($failure);
                    throw $failure;
                }
            }
        }

        if (!$target->isWritable()) { // @phpstan-ignore booleanNot.alwaysFalse
            $request->abort('response closed before body');
            throw new ResponseWriteFailure('OpenSwoole response closed before body.');
        }

        $request->bodyStarted();

        if (!$target->end((string) $source->getBody())) {
            $failure = new ResponseWriteFailure('OpenSwoole failed to finish response body.');
            $request->fail($failure);
            throw $failure;
        }
    }
}

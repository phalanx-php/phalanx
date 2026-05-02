<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;

final readonly class StoaResponseWriter
{
    public function write(ResponseInterface $source, Response $target, RequestLifecycle $lifecycle): void
    {
        if (!$target->isWritable()) {
            $lifecycle->abort('response is not writable before headers');
            throw new ResponseWriteFailure('OpenSwoole response is not writable before headers.');
        }

        if (!$target->status($source->getStatusCode(), $source->getReasonPhrase())) {
            $lifecycle->fail(new ResponseWriteFailure('OpenSwoole failed to set response status.'));
            throw new ResponseWriteFailure('OpenSwoole failed to set response status.');
        }

        $lifecycle->headersStarted();

        foreach ($source->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                if (!$target->header($name, $value)) {
                    $failure = new ResponseWriteFailure("OpenSwoole failed to write response header '{$name}'.");
                    $lifecycle->fail($failure);
                    throw $failure;
                }
            }
        }

        if (!$target->isWritable()) {
            $lifecycle->abort('response closed before body');
            throw new ResponseWriteFailure('OpenSwoole response closed before body.');
        }

        $lifecycle->bodyStarted();

        if (!$target->end((string) $source->getBody())) {
            $failure = new ResponseWriteFailure('OpenSwoole failed to finish response body.');
            $lifecycle->fail($failure);
            throw $failure;
        }
    }
}

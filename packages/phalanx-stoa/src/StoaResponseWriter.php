<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use OpenSwoole\Core\Psr\Response as PsrResponseHelper;
use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;

final readonly class StoaResponseWriter
{
    private const int CHUNK_SIZE = PsrResponseHelper::CHUNK_SIZE;

    public function write(ResponseInterface $source, Response $target, StoaRequestResource $request): void
    {
        if (!$target->isWritable()) {
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

        if (!$target->isWritable()) {
            $request->abort('response closed before body');
            throw new ResponseWriteFailure('OpenSwoole response closed before body.');
        }

        $request->bodyStarted();

        $body = $source->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $size = $body->getSize();

        if ($size !== null && $size <= self::CHUNK_SIZE) {
            if (!$target->end($body->getContents())) {
                throw new ResponseWriteFailure('OpenSwoole failed to finish response body.');
            }

            return;
        }

        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK_SIZE);

            if ($chunk === '') {
                break;
            }

            if ($target->write($chunk) === false) {
                throw new ResponseWriteFailure('OpenSwoole failed to write response body chunk.');
            }
        }

        if (!$target->end()) {
            throw new ResponseWriteFailure('OpenSwoole failed to finish response body.');
        }
    }
}

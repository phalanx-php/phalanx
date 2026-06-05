<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Response;

final readonly class ResponseWriter
{
    private const int CHUNK_SIZE = 2097152;

    public function write(ResponseInterface $source, Response $target, \Phalanx\Http\RequestResource $request): void
    {
        if (!$target->isWritable()) {
            $request->abort('response is not writable before headers');

            throw new ResponseWriteFailure('Swoole response is not writable before headers.');
        }

        if (!$target->status($source->getStatusCode(), $source->getReasonPhrase())) {
            throw new ResponseWriteFailure('Swoole failed to set response status.');
        }

        $request->headersStarted();

        foreach ($source->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                if (!$target->header($name, $value)) {
                    throw new ResponseWriteFailure("Swoole failed to write response header '{$name}'.");
                }
            }
        }

        // @phpstan-ignore booleanNot.alwaysFalse (client disconnect between header writes)
        if (!$target->isWritable()) {
            $request->abort('response closed before body');

            throw new ResponseWriteFailure('Swoole response closed before body.');
        }

        $request->bodyStarted();

        $body = $source->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $size = $body->getSize();

        if ($size !== null && $size <= self::CHUNK_SIZE) {
            if (!$target->end($body->getContents())) {
                throw new ResponseWriteFailure('Swoole failed to finish response body.');
            }

            return;
        }

        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK_SIZE);

            if ($chunk === '') {
                break;
            }

            if ($target->write($chunk) === false) {
                throw new ResponseWriteFailure('Swoole failed to write response body chunk.');
            }
        }

        if (!$target->end()) {
            throw new ResponseWriteFailure('Swoole failed to finish response body.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Worker\Protocol\Response;
use Phalanx\Worker\Protocol\TaskRequest;
use RuntimeException;

/**
 * JSON line-delimited message codec for parent <-> worker IPC.
 *
 * Each frame is a single JSON object terminated by `\n`. Payloads (the task
 * itself, the return value, the error record) are PHP-serialized strings
 * embedded as JSON string fields so the child can unserialize them directly.
 *
 * JSON wraps the envelope (kind + id + serialized payload) so framing is
 * line-based and trivially debuggable; the inner serialize() handles arbitrary
 * PHP value types including objects with private state.
 */
class Codec
{
    public static function encodeRequest(TaskRequest $req): string
    {
        $json = json_encode([
            'kind' => 'request',
            'id' => $req->id,
            'task' => base64_encode($req->serializedTask),
        ], JSON_THROW_ON_ERROR);
        return $json . "\n";
    }

    public static function encodeResponse(Response $resp): string
    {
        $json = json_encode([
            'kind' => 'response',
            'id' => $resp->id,
            'result' => $resp->kind,
            'value' => base64_encode($resp->serializedValue),
        ], JSON_THROW_ON_ERROR);
        return $json . "\n";
    }

    public static function decodeRequest(string $line): TaskRequest
    {
        $data = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['kind'] ?? null) !== 'request') {
            throw new RuntimeException('Codec: not a request frame');
        }
        return new TaskRequest((string) $data['id'], base64_decode((string) $data['task'], true) ?: '');
    }

    public static function decodeResponse(string $line): Response
    {
        $data = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['kind'] ?? null) !== 'response') {
            throw new RuntimeException('Codec: not a response frame');
        }
        return new Response(
            (string) $data['id'],
            (string) $data['result'],
            base64_decode((string) $data['value'], true) ?: '',
        );
    }
}

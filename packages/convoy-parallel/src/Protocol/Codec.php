<?php

declare(strict_types=1);

namespace Convoy\Parallel\Protocol;

use JsonException;

final class Codec
{
    public static function encode(TaskRequest|ServiceCall|Response $message): string
    {
        return json_encode($message->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @return TaskRequest|ServiceCall|Response
     * @throws JsonException|InvalidMessageException
     */
    public static function decode(string $line): TaskRequest|ServiceCall|Response
    {
        $data = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['type'])) {
            throw new InvalidMessageException('Missing type field in message');
        }

        $type = MessageType::tryFrom($data['type']);

        if ($type === null) {
            throw new InvalidMessageException("Unknown message type: {$data['type']}");
        }

        return match ($type) {
            MessageType::TaskRequest => TaskRequest::fromArray($data),
            MessageType::ServiceCall => ServiceCall::fromArray($data),
            MessageType::TaskResponse, MessageType::ServiceResponse => Response::fromArray($data),
        };
    }

    public static function decodeTaskRequest(string $line): TaskRequest
    {
        $message = self::decode($line);

        if (!$message instanceof TaskRequest) {
            throw new InvalidMessageException('Expected TaskRequest');
        }

        return $message;
    }

    public static function decodeResponse(string $line): Response
    {
        $message = self::decode($line);

        if (!$message instanceof Response) {
            throw new InvalidMessageException('Expected Response');
        }

        return $message;
    }

    public static function decodeServiceCall(string $line): ServiceCall
    {
        $message = self::decode($line);

        if (!$message instanceof ServiceCall) {
            throw new InvalidMessageException('Expected ServiceCall');
        }

        return $message;
    }
}

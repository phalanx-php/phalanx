<?php

declare(strict_types=1);

namespace Phalanx\Athena\Output;

use Phalanx\Athena\Exception\OutputHydrationError;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Output\Mode;
use Phalanx\Scope\TaskScope;

final class OutputHydrator
{
    public static function hydrate(TaskScope $scope, mixed $raw, Agent $agent): ?object
    {
        $scope->throwIfCancelled();

        if ($agent->output->mode !== Mode::Structured) {
            return null;
        }

        $schema = $agent->output->schema;
        if ($schema === null) {
            throw new OutputHydrationError('Structured output mode requires a schema class-string.');
        }

        if (!is_string($raw)) {
            throw new OutputHydrationError('Expected JSON string for structured output, got ' . get_debug_type($raw));
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OutputHydrationError('Failed to decode output JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new OutputHydrationError('Decoded output must be an array, got ' . get_debug_type($decoded));
        }

        try {
            return new $schema(...$decoded);
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new OutputHydrationError(
                sprintf('Failed to construct %s: %s', $schema, $e->getMessage()),
                previous: $e,
            );
        }
    }
}

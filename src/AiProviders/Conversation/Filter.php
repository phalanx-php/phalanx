<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Conversation;

/**
 * Static factory methods for common {@see Log} predicates. Every factory
 * returns a `static` closure — required for memory safety in long-running
 * processes and enforced by the PHPStan safety compiler.
 *
 * Pass the returned closure to {@see \Phalanx\AiProviders\Series::where()} or
 * any other combinator that accepts a predicate.
 */
final class Filter
{
    private function __construct()
    {
    }

    public static function byType(RecordType $type): \Closure
    {
        return static fn (Record $record): bool => $record->type === $type;
    }

    public static function byRole(string $role): \Closure
    {
        return static fn (Record $record): bool =>
            $record instanceof Record\Message && $record->role === $role;
    }

    public static function sinceTime(\DateTimeImmutable $threshold): \Closure
    {
        return static fn (Record $record): bool => $record->at >= $threshold;
    }

    public static function untilTime(\DateTimeImmutable $threshold): \Closure
    {
        return static fn (Record $record): bool => $record->at < $threshold;
    }
}

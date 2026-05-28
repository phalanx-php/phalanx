<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Runtime;

use Phalanx\Swoole\Mvp\Profile\Composes;
use Phalanx\Swoole\Mvp\Profile\Computes;
use Phalanx\Swoole\Mvp\Profile\Pure;
use Phalanx\Swoole\Mvp\Profile\Reads;
use Phalanx\Swoole\Mvp\Profile\Writes;

final class TaskMetadata
{
    public const PROFILE_PURE = 'pure';
    public const PROFILE_READS = 'reads';
    public const PROFILE_WRITES = 'writes';
    public const PROFILE_COMPUTES = 'computes';
    public const PROFILE_COMPOSES = 'composes';

    /**
     * @param class-string $class
     * @param self::PROFILE_* $profile
     * @param list<class-string> $reads
     * @param array<class-string, list<string>> $writes
     * @param (\Closure(object): list<mixed>)|null $keyExtractor
     */
    public function __construct(
        public readonly string $class,
        public readonly string $profile,
        public readonly array $reads,
        public readonly array $writes,
        public readonly ?\Closure $keyExtractor,
    ) {}

    /**
     * @param class-string $class
     */
    public static function detectProfile(string $class): string
    {
        $implements = class_implements($class) ?: [];
        $profiles = array_intersect(array_keys(array_flip([
            Pure::class, Reads::class, Writes::class, Computes::class, Composes::class,
        ])), $implements);
        if (count($profiles) !== 1) {
            throw new CompileException(sprintf(
                'Task %s must implement exactly one profile interface; found [%s].',
                $class,
                implode(', ', $profiles),
            ));
        }
        return match (reset($profiles)) {
            Pure::class => self::PROFILE_PURE,
            Reads::class => self::PROFILE_READS,
            Writes::class => self::PROFILE_WRITES,
            Computes::class => self::PROFILE_COMPUTES,
            Composes::class => self::PROFILE_COMPOSES,
            default => throw new CompileException("Unreachable for {$class}."),
        };
    }
}

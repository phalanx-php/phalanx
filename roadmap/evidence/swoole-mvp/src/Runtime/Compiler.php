<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Runtime;

use Phalanx\Swoole\Mvp\Profile\Reads;
use Phalanx\Swoole\Mvp\Profile\Writes;
use Phalanx\Swoole\Mvp\Service\ResourceDescriptor;

final class Compiler
{
    /**
     * @param list<class-string> $taskClasses
     * @param array<class-string, ResourceDescriptor> $resources
     * @return array<class-string, TaskMetadata>
     */
    public static function compile(array $taskClasses, array $resources): array
    {
        $compiled = [];
        foreach ($taskClasses as $class) {
            if (! class_exists($class)) {
                throw new CompileException("Task class {$class} does not exist.");
            }
            $profile = TaskMetadata::detectProfile($class);
            $reads = [];
            $writes = [];
            $extractor = null;

            if ($profile === TaskMetadata::PROFILE_READS) {
                /** @var list<class-string> $reads */
                $reads = self::validateReads($class, $resources);
            }
            if ($profile === TaskMetadata::PROFILE_WRITES) {
                /** @var array<class-string, list<string>> $writes */
                $writes = self::validateWrites($class, $resources);
                $extractor = self::buildExtractor($class, $writes);
            }

            $compiled[$class] = new TaskMetadata($class, $profile, $reads, $writes, $extractor);
        }
        self::validateCapacity($compiled, $resources);
        return $compiled;
    }

    /**
     * @param class-string $class
     * @param array<class-string, ResourceDescriptor> $resources
     * @return list<class-string>
     */
    private static function validateReads(string $class, array $resources): array
    {
        /** @var list<class-string> $declared */
        $declared = $class::reads();
        if (! is_array($declared)) {
            throw new CompileException("{$class}::reads() must return an array.");
        }
        foreach ($declared as $r) {
            if (! is_string($r) || ! isset($resources[$r])) {
                throw new CompileException(
                    "{$class}::reads() declares unregistered resource " . var_export($r, true) . '.'
                );
            }
        }
        return $declared;
    }

    /**
     * @param class-string $class
     * @param array<class-string, ResourceDescriptor> $resources
     * @return array<class-string, list<string>>
     */
    private static function validateWrites(string $class, array $resources): array
    {
        /** @var array<class-string, list<string>> $declared */
        $declared = $class::writes();
        if (! is_array($declared)) {
            throw new CompileException("{$class}::writes() must return a map.");
        }
        $reflection = new \ReflectionClass($class);
        foreach ($declared as $resource => $properties) {
            if (! is_string($resource) || ! isset($resources[$resource])) {
                throw new CompileException(
                    "{$class}::writes() declares unregistered resource " . var_export($resource, true) . '.'
                );
            }
            if (! is_array($properties)) {
                throw new CompileException(
                    "{$class}::writes()[{$resource}] must be a list of property names."
                );
            }
            foreach ($properties as $prop) {
                if (! is_string($prop) || ! $reflection->hasProperty($prop)) {
                    throw new CompileException(sprintf(
                        '%s::writes()[%s] references property "%s" which does not exist on the task.',
                        $class,
                        $resource,
                        is_string($prop) ? $prop : var_export($prop, true),
                    ));
                }
                $rp = $reflection->getProperty($prop);
                if (! $rp->isPublic()) {
                    throw new CompileException(sprintf(
                        '%s::$%s must be public for key extraction.',
                        $class,
                        $prop,
                    ));
                }
            }
        }
        return $declared;
    }

    /**
     * @param class-string $class
     * @param array<class-string, list<string>> $writes
     * @return \Closure(object): list<mixed>
     */
    private static function buildExtractor(string $class, array $writes): \Closure
    {
        $allProps = [];
        foreach ($writes as $properties) {
            foreach ($properties as $prop) {
                $allProps[$prop] = true;
            }
        }
        $properties = array_keys($allProps);

        return static function (object $task) use ($class, $properties): array {
            if (! $task instanceof $class) {
                throw new \LogicException(
                    'Extractor invoked with wrong task type ' . $task::class . " for {$class}."
                );
            }
            $values = [];
            foreach ($properties as $prop) {
                $values[$prop] = $task->{$prop};
            }
            return $values;
        };
    }

    /**
     * @param array<class-string, TaskMetadata> $compiled
     * @param array<class-string, ResourceDescriptor> $resources
     */
    private static function validateCapacity(array $compiled, array $resources): void
    {
        foreach ($resources as $class => $descriptor) {
            if ($descriptor->capacity === null) {
                throw new CompileException("Resource {$class} must declare capacity().");
            }
            if ($descriptor->factory === null) {
                throw new CompileException("Resource {$class} must declare factory().");
            }
        }
    }
}

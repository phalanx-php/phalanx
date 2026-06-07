<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Identity\RuntimeCounterId;
use Phalanx\Runtime\Identity\RuntimeEventId;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Runtime\Identity\RuntimeServiceId;
use ReflectionEnum;

#[Group('architecture')]
final class RuntimeIdentityEnumCoverageTest extends TestCase
{
    #[Test]
    public function runtime_identity_enums_are_string_backed_and_unique(): void
    {
        $seen = [];
        $violations = [];
        $files = self::sidFiles();

        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $relative = self::relative($file);
            $class = self::className($file);

            if ($class === null || !enum_exists($class)) {
                $violations[] = "{$relative} does not declare a loadable Sid enum";
                continue;
            }

            $reflection = new ReflectionEnum($class);
            $expectedInterface = self::expectedInterface($reflection->getShortName());

            if (!$reflection->isBacked() || $reflection->getBackingType()?->getName() !== 'string') {
                $violations[] = "{$relative} is not string-backed";
                continue;
            }

            if ($expectedInterface === null || !$reflection->implementsInterface($expectedInterface)) {
                $violations[] = "{$relative} does not implement the matching runtime ID interface";
                continue;
            }

            foreach ($reflection->getCases() as $case) {
                $sid = $case->getValue();
                if (!$sid instanceof RuntimeServiceId) {
                    $violations[] = "{$relative} case {$case->getName()} is not a runtime service id";
                    continue;
                }

                $value = $case->getBackingValue();
                if ($sid->key() !== $case->getName()) {
                    $violations[] = "{$relative} case {$case->getName()} key() must return the enum case name";
                }
                if ($sid->value() !== $value) {
                    $violations[] = "{$relative} case {$case->getName()} value() must return the enum backing value";
                }

                if (!str_contains($value, '.')) {
                    $violations[] = "{$relative} value {$value} is not namespaced";
                }

                if (isset($seen[$value])) {
                    $violations[] = "{$relative} duplicates {$value} from {$seen[$value]}";
                }
                $seen[$value] = $relative;
            }
        }

        self::assertSame([], $violations);
    }

    /** @return list<string> */
    private static function sidFiles(): array
    {
        $files = glob(self::root() . '/src/*/src/Runtime/Identity/*Sid.php') ?: [];
        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function relative(string $path): string
    {
        return ltrim(str_replace(self::root(), '', $path), '/');
    }

    /** @return class-string|null */
    private static function className(string $file): ?string
    {
        $source = (string) file_get_contents($file);
        if (preg_match('/namespace\s+([^;]+);/', $source, $namespace) !== 1) {
            return null;
        }
        if (preg_match('/enum\s+(\w+Sid)\s*:/', $source, $name) !== 1) {
            return null;
        }

        /** @var class-string $class */
        $class = $namespace[1] . '\\' . $name[1];

        return $class;
    }

    /** @return class-string<RuntimeServiceId>|null */
    private static function expectedInterface(string $shortName): ?string
    {
        return match (true) {
            str_ends_with($shortName, 'ResourceSid') => RuntimeResourceId::class,
            str_ends_with($shortName, 'AnnotationSid') => RuntimeAnnotationId::class,
            str_ends_with($shortName, 'EventSid') => RuntimeEventId::class,
            str_ends_with($shortName, 'CounterSid') => RuntimeCounterId::class,
            default => null,
        };
    }
}

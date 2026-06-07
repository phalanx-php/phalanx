<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class RuntimeIdentityEnumCoverageTest extends TestCase
{
    #[Test]
    public function runtime_identity_enums_are_string_backed_and_unique(): void
    {
        $seen = [];
        $violations = [];

        foreach (self::sidFiles() as $file) {
            $source = (string) file_get_contents($file);
            $relative = self::relative($file);

            if (preg_match('/enum\s+\w+Sid:\s+string\s+implements\s+Runtime(?:Resource|Annotation|Event|Counter)Id/', $source) !== 1) {
                $violations[] = "{$relative} is not a string-backed runtime Sid enum";
                continue;
            }

            if (!str_contains($source, 'return $this->name;') || !str_contains($source, 'return $this->value;')) {
                $violations[] = "{$relative} must expose key() and value() through the enum name/value";
            }

            preg_match_all('/case\s+\w+\s*=\s*\\\'([^\\\']+)\\\'/', $source, $matches);
            foreach ($matches[1] as $value) {
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
}

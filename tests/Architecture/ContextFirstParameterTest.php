<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class ContextFirstParameterTest extends TestCase
{
    #[Test]
    public function ctx_and_scope_parameters_are_first_when_declared(): void
    {
        $violations = [];

        foreach (self::phpFiles() as $path) {
            foreach (self::violations($path) as $line => $message) {
                $violations[] = sprintf('%s:%d %s', self::relativePath($path), $line, $message);
            }
        }

        self::assertSame([], $violations);
    }

    #[Test]
    public function context_first_scanner_detects_inline_fixture_violations(): void
    {
        self::assertSame(
            ['declares $ctx after $item'],
            array_values(self::violationsForSource('<?php function bad($item, $ctx): void {}')),
        );

        self::assertSame(
            [],
            array_values(self::violationsForSource('<?php function good($ctx, $item): void {}')),
        );

        self::assertSame(
            ['declares $rootScope after $argv'],
            array_values(self::violationsForSource(
                '<?php use Phalanx\Scope\ExecutionScope; function badAlias(array $argv, ExecutionScope $rootScope): void {}',
            )),
        );

        self::assertSame(
            [],
            array_values(self::violationsForSource(
                '<?php use Phalanx\Scope\ExecutionScope; function goodAlias(ExecutionScope $rootScope, array $argv): void {}',
            )),
        );
    }

    /** @return list<string> */
    private static function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['src', 'tests', 'demos', 'benchmarks'] as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $pathname = $file->getPathname();
                if (self::isExcluded($pathname)) {
                    continue;
                }

                $files[] = $pathname;
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<int, string> */
    private static function violations(string $path): array
    {
        return self::violationsForSource((string) file_get_contents($path));
    }

    /** @return array<int, string> */
    private static function violationsForSource(string $source): array
    {
        $tokens = token_get_all($source);
        $violations = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;
            $isFunction = $id === T_FUNCTION || (defined('T_FN') && $id === T_FN);

            if (!$isFunction) {
                continue;
            }

            $line = is_array($token) ? $token[2] : 0;
            while ($i < $count && $tokens[$i] !== '(') {
                $i++;
            }

            if ($i >= $count) {
                continue;
            }

            $parameters = self::parameters($tokens, $i, $count);
            if ($parameters === []) {
                continue;
            }

            $contextParameters = array_values(array_filter(
                $parameters,
                static fn(array $parameter): bool => self::isContextParameter($parameter),
            ));

            if ($contextParameters === [] || self::isContextParameter($parameters[0])) {
                continue;
            }

            $violations[$line] = sprintf(
                'declares $%s after $%s',
                implode('/$', array_column($contextParameters, 'name')),
                $parameters[0]['name'],
            );
        }

        return $violations;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{name:string,type:string}>
     */
    private static function parameters(array $tokens, int $offset, int $count): array
    {
        $depth = 0;
        $parameters = [];
        $typeParts = [];

        for ($i = $offset; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(') {
                $depth++;
                continue;
            }

            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($token === ',') {
                $typeParts = [];
                continue;
            }

            if (is_string($token)) {
                if (in_array($token, ['?', '|', '&', '\\'], true)) {
                    $typeParts[] = $token;
                }
                continue;
            }

            if ($token[0] === T_VARIABLE) {
                $parameters[] = [
                    'name' => substr($token[1], 1),
                    'type' => implode('', $typeParts),
                ];
                $typeParts = [];
                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $typeParts[] = $token[1];
            }
        }

        return $parameters;
    }

    /** @param array{name:string,type:string} $parameter */
    private static function isContextParameter(array $parameter): bool
    {
        $name = $parameter['name'];
        $lowerName = strtolower($name);
        $type = ltrim($parameter['type'], '?\\');
        $shortType = preg_replace('/^.*\\\\/', '', $type) ?? $type;

        return in_array($lowerName, ['ctx', 'scope'], true)
            || str_ends_with($lowerName, 'ctx')
            || str_ends_with($shortType, 'Scope');
    }

    private static function isExcluded(string $path): bool
    {
        return str_contains($path, '/resources/')
            || str_contains($path, '/src/PHPStan/src/')
            || str_contains($path, '/src/PHPStan/tests/Rules/Fixtures/');
    }

    private static function relativePath(string $path): string
    {
        return str_replace(dirname(__DIR__, 2) . '/', '', $path);
    }
}

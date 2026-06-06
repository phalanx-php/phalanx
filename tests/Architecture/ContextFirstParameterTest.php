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
        $tokens = token_get_all((string) file_get_contents($path));
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

            $variables = self::parameterVariables($tokens, $i, $count);
            if ($variables === []) {
                continue;
            }

            $hasContext = in_array('ctx', $variables, true) || in_array('scope', $variables, true);
            if (!$hasContext || in_array($variables[0], ['ctx', 'scope'], true)) {
                continue;
            }

            $contextVars = array_values(array_intersect($variables, ['ctx', 'scope']));
            $violations[$line] = sprintf(
                'declares $%s after $%s',
                implode('/$', $contextVars),
                $variables[0],
            );
        }

        return $violations;
    }

    /**
     * @param list<array|string> $tokens
     * @return list<string>
     */
    private static function parameterVariables(array $tokens, int $offset, int $count): array
    {
        $depth = 0;
        $variables = [];

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

            if ($depth === 1 && is_array($token) && $token[0] === T_VARIABLE) {
                $variables[] = substr($token[1], 1);
            }
        }

        return $variables;
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

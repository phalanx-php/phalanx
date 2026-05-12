<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Scope\ExecutionScope;

function testUnusedClosureParameters(): void
{
    // Should error: $scope declared but never used
    $fn1 = static function (ExecutionScope $scope): void {
        echo 'hello';
    };

    // Should NOT error: $scope is used
    $fn2 = static function (ExecutionScope $scope): void {
        $scope->throwIfCancelled();
    };

    // Should NOT error: $_scope is prefixed
    $fn3 = static function (ExecutionScope $_scope): void {
        echo 'hello';
    };

    // Should error: $x declared but never used in arrow function
    $fn4 = static fn(int $x): int => 42;

    // Should NOT error: $x is used in arrow function
    $fn5 = static fn(int $x): int => $x + 1;

    // Should error: $a unused, $b used
    $fn6 = static function (int $a, int $b): int {
        return $b * 2;
    };

    // Should NOT error: both used
    $fn7 = static function (int $a, int $b): int {
        return $a + $b;
    };

    // Should NOT error: $_unused is prefixed
    $fn8 = static function (int $_unused, int $b): int {
        return $b;
    };

    // Prevent "unused variable" noise
    array_map(static fn($f) => $f, [$fn1, $fn2, $fn3, $fn4, $fn5, $fn6, $fn7, $fn8]);
}

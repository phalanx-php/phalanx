<?php

declare(strict_types=1);

namespace AegisSwoole\Tests;

use AegisSwoole\Scope\CoroutineScopeRegistry;
use AegisSwoole\Scope\ExecutionScope;
use Closure;
use Throwable;

class Harness
{
    /** @var list<array{name: string, fn: Closure(ExecutionScope): Result}> */
    private array $scenarios = [];

    private int $passed = 0;
    private int $failed = 0;

    /** @param Closure(ExecutionScope): Result $fn */
    public function add(string $name, Closure $fn): void
    {
        $this->scenarios[] = ['name' => $name, 'fn' => $fn];
    }

    /** @param Closure(): ExecutionScope $scopeFactory  fresh scope per scenario */
    public function run(Closure $scopeFactory): bool
    {
        foreach ($this->scenarios as ['name' => $name, 'fn' => $fn]) {
            $scope = $scopeFactory();
            $previous = CoroutineScopeRegistry::current();
            CoroutineScopeRegistry::install($scope);
            try {
                $result = $fn($scope);
                if ($result->ok) {
                    fwrite(STDOUT, "[PASS] {$name}\n");
                    $this->passed++;
                } else {
                    fwrite(STDOUT, "[FAIL] {$name}: {$result->reason}\n");
                    $this->failed++;
                }
            } catch (Throwable $e) {
                $type = $e::class;
                $msg = $e->getMessage();
                fwrite(STDOUT, "[FAIL] {$name}: uncaught {$type}: {$msg}\n");
                $this->failed++;
            } finally {
                if ($previous !== null) {
                    CoroutineScopeRegistry::install($previous);
                } else {
                    CoroutineScopeRegistry::clear();
                }
                $scope->dispose();
            }
        }

        $total = $this->passed + $this->failed;
        fwrite(STDOUT, "\n{$this->passed}/{$total} passed\n");
        return $this->failed === 0;
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Scope;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\VariadicPlaceholder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<MethodCall>
 */
final class StaticClosureOnlyRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.scope.staticClosureOnly';

    /** @var list<string> */
    private const array TASK_METHODS = [
        'any',
        'concurrent',
        'defer',
        'go',
        'inWorker',
        'map',
        'race',
        'retry',
        'series',
        'settle',
        'singleflight',
        'timeout',
        'waterfall',
    ];

    public function __construct(private readonly PathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        if (!$node instanceof MethodCall) {
            return [];
        }

        $method = NodeNames::calledMethodName($node);
        if ($method === null || !in_array($method, self::TASK_METHODS, true)) {
            return [];
        }

        foreach ($this->closuresToCheck($method, $this->args($node->args)) as $closure) {
            if ($closure->static) {
                continue;
            }

            return RuleErrors::build(
                sprintf(
                    'Closure passed to %s() must be static so it cannot capture $this in a long-running coroutine.',
                    $method,
                ),
                self::IDENTIFIER,
                $closure->getLine(),
            );
        }

        return [];
    }

    /**
     * @param array<Arg|VariadicPlaceholder> $args
     * @return list<Arg>
     */
    private function args(array $args): array
    {
        return array_values(array_filter(
            $args,
            static fn(Arg|VariadicPlaceholder $arg): bool => $arg instanceof Arg,
        ));
    }

    /**
     * @param array<Arg> $args
     * @return list<Closure|ArrowFunction>
     */
    private function closuresToCheck(string $method, array $args): array
    {
        return match ($method) {
            'any',
            'concurrent',
            'race',
            'series',
            'settle',
            'waterfall' => $this->taskListClosures($args),
            'map' => $this->directClosures($args, [1, 3]),
            'retry',
            'defer',
            'go',
            'inWorker',
            'timeout' => $this->directClosures($args, [0]),
            'singleflight' => $this->directClosures($args, [1]),
            default => [],
        };
    }

    /**
     * @param array<Arg> $args
     * @return list<Closure|ArrowFunction>
     */
    private function taskListClosures(array $args): array
    {
        $closures = [];
        foreach ($args as $arg) {
            $value = $arg->value;
            if ($value instanceof ArrowFunction || $value instanceof Closure) {
                $closures[] = $value;
                continue;
            }

            if ($arg->unpack && $value instanceof Array_) {
                array_push($closures, ...$this->arrayClosures($value));
            }
        }

        return $closures;
    }

    /**
     * @param array<Arg> $args
     * @param list<int> $positions
     * @return list<Closure|ArrowFunction>
     */
    private function directClosures(array $args, array $positions): array
    {
        $closures = [];
        foreach ($positions as $position) {
            $value = $args[$position]->value ?? null;
            if ($value instanceof ArrowFunction || $value instanceof Closure) {
                $closures[] = $value;
            }
        }

        return $closures;
    }

    /**
     * @return list<Closure|ArrowFunction>
     */
    private function arrayClosures(Node\Expr|null $expr): array
    {
        if (!$expr instanceof Array_) {
            return [];
        }

        $closures = [];
        foreach ($expr->items as $item) {
            if ($item->value instanceof ArrowFunction || $item->value instanceof Closure) {
                $closures[] = $item->value;
            }
        }

        return $closures;
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Worker;

use Closure;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<MethodCall>
 */
final class NoClosureBoundaryRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.worker.noClosureBoundary';

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
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (NodeNames::calledMethodName($node) !== 'inWorker' || !$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        $task = $node->args[0]->value ?? null;
        if ($task === null) {
            return [];
        }

        if ($task instanceof ArrowFunction || $task instanceof \PhpParser\Node\Expr\Closure || $this->isTaskFactoryClosure($task, $scope)) {
            return $this->error($node);
        }

        $type = $scope->getType($task);
        if ((new ObjectType(Closure::class))->isSuperTypeOf($type)->yes()) {
            return $this->error($node);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function error(MethodCall $node): array
    {
        return RuleErrors::build(
            'inWorker() cannot receive a closure or Task::of() closure adapter; pass a serializable Scopeable|Executable task object.',
            self::IDENTIFIER,
            $node->getLine(),
        );
    }

    private function isTaskFactoryClosure(\PhpParser\Node\Expr $expr, Scope $scope): bool
    {
        if (!$expr instanceof StaticCall) {
            return false;
        }

        $class = NodeNames::calledClassName($expr, $scope);
        $method = NodeNames::calledMethodName($expr);

        return $class === 'Phalanx\\Task\\Task'
            && in_array($method, ['named', 'of'], true);
    }
}

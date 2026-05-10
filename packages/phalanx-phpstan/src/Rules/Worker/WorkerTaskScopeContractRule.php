<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Worker;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\UnionType;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\Worker\WorkerTask;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Class_>
 */
final class WorkerTaskScopeContractRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.worker.scopeContract';

    /** @var list<string> */
    private const array ALLOWED_SCOPE_TYPES = [
        'Phalanx\\Scope\\Scope',
        'Phalanx\\Worker\\WorkerScope',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_SCOPE_TYPES = [
        'Phalanx\\Scope\\ExecutionScope',
        'Phalanx\\Scope\\TaskExecutor',
        'Phalanx\\Scope\\TaskScope',
    ];

    public function __construct(
        private readonly PathPolicy $paths,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        if (!$this->implementsWorkerTask($node, $scope)) {
            return [];
        }

        $invoke = $node->getMethod('__invoke');
        $firstParam = $invoke?->params[0] ?? null;
        if ($firstParam === null || $firstParam->type === null) {
            return RuleErrors::build(
                'WorkerTask::__invoke() must accept Phalanx\\Worker\\WorkerScope or Phalanx\\Scope\\Scope.',
                self::IDENTIFIER,
                $invoke?->getLine() ?? $node->getLine(),
            );
        }

        $types = $this->typeNames($firstParam->type, $scope);
        if ($types !== [] && array_all($types, static fn(string $type): bool => in_array($type, self::ALLOWED_SCOPE_TYPES, true))) {
            return [];
        }

        $type = $types[0] ?? null;
        if (in_array($type, self::FORBIDDEN_SCOPE_TYPES, true)) {
            return RuleErrors::build(
                'WorkerTask::__invoke() must accept WorkerScope or narrow Scope, not ExecutionScope/TaskScope/TaskExecutor.',
                self::IDENTIFIER,
                $firstParam->getLine(),
            );
        }

        return RuleErrors::build(
            'WorkerTask::__invoke() must accept Phalanx\\Worker\\WorkerScope or Phalanx\\Scope\\Scope.',
            self::IDENTIFIER,
            $firstParam->getLine(),
        );
    }

    /** @return list<string> */
    private function typeNames(Node $typeNode, Scope $scope): array
    {
        if ($typeNode instanceof UnionType) {
            $types = [];
            foreach ($typeNode->types as $type) {
                array_push($types, ...$this->typeNames($type, $scope));
            }
            return $types;
        }

        if (!$typeNode instanceof Name) {
            return [];
        }

        $type = NodeNames::resolvedTypeName($typeNode, $scope);
        return $type === null ? [] : [$type];
    }

    private function implementsWorkerTask(Class_ $class, Scope $scope): bool
    {
        foreach ($class->implements as $interface) {
            $type = $scope->resolveName($interface);
            if ($type === WorkerTask::class) {
                return true;
            }

            if (
                $this->reflectionProvider->hasClass($type)
                && $this->reflectionProvider->getClass($type)->implementsInterface(WorkerTask::class)
            ) {
                return true;
            }
        }

        if ($class->extends !== null) {
            $parent = $scope->resolveName($class->extends);
            return $this->reflectionProvider->hasClass($parent)
                && $this->reflectionProvider->getClass($parent)->implementsInterface(WorkerTask::class);
        }

        return false;
    }
}

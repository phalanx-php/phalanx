<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Worker;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\Node\Stmt\Class_;
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
final class WorkerTaskSerializableStateRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.worker.serializableTaskState';

    /** @var list<string> */
    private const array FORBIDDEN_TYPES = [
        'Closure',
        'Phalanx\\Cancellation\\CancellationToken',
        'Phalanx\\Runtime\\RuntimeContext',
        'Phalanx\\Scope\\ExecutionScope',
        'Phalanx\\Scope\\Scope',
        'Phalanx\\Scope\\TaskExecutor',
        'Phalanx\\Scope\\TaskScope',
        'Phalanx\\Supervisor\\TransactionLease',
        'Phalanx\\Worker\\WorkerDispatch',
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

        foreach ($node->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $error = $this->stateError(
                $property->type,
                $property->getLine(),
                $scope,
                $property->getDocComment()?->getText(),
            );
            if ($error !== null) {
                return $error;
            }
        }

        $constructor = $node->getMethod('__construct');
        foreach ($constructor->params ?? [] as $param) {
            if ($param->flags === 0) {
                continue;
            }

            $error = $this->stateError($param->type, $param->getLine(), $scope);
            if ($error !== null) {
                return $error;
            }
        }

        return [];
    }

    /** @return list<IdentifierRuleError>|null */
    private function stateError(Node|null $typeNode, int $line, Scope $scope, ?string $doc = null): ?array
    {
        if ($typeNode === null) {
            return RuleErrors::build(
                'WorkerTask state must be typed as serializable scalar/array/enum data before crossing a process boundary.',
                self::IDENTIFIER,
                $line,
            );
        }

        $typeNames = $this->typeNames($typeNode, $scope);
        if ($typeNames === []) {
            return RuleErrors::build(
                'WorkerTask state must be serializable scalar/array/enum data.',
                self::IDENTIFIER,
                $line,
            );
        }

        foreach ($typeNames as $type) {
            if (strtolower($type) === 'array') {
                $arrayError = $this->documentedArrayError($doc, $line, $scope);
                if ($arrayError !== null) {
                    return $arrayError;
                }
            }

            if (!$this->isSerializableType($type)) {
                if (in_array($type, self::FORBIDDEN_TYPES, true)) {
                    return RuleErrors::build(
                        sprintf('WorkerTask state cannot store %s across a process boundary.', $type),
                        self::IDENTIFIER,
                        $line,
                    );
                }

                return RuleErrors::build(
                    sprintf('WorkerTask state type %s is not process-boundary serializable; pass scalar/array/enum DTO data.', $type),
                    self::IDENTIFIER,
                    $line,
                );
            }
        }

        return null;
    }

    /** @return list<IdentifierRuleError>|null */
    private function documentedArrayError(?string $doc, int $line, Scope $scope): ?array
    {
        if ($doc === null || !preg_match_all('/[A-Z][A-Za-z0-9_\\\\]*/', $doc, $matches)) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $type = $candidate === 'Closure' ? 'Closure' : $scope->resolveName(new Name($candidate));
            if ($this->isSerializableType($type)) {
                continue;
            }

            return RuleErrors::build(
                sprintf('WorkerTask array state cannot contain %s across a process boundary.', $type),
                self::IDENTIFIER,
                $line,
            );
        }

        return null;
    }

    /** @return list<string> */
    private function typeNames(Node $typeNode, Scope $scope): array
    {
        if ($typeNode instanceof NullableType) {
            return $this->typeNames($typeNode->type, $scope);
        }

        if ($typeNode instanceof UnionType) {
            $names = [];
            foreach ($typeNode->types as $type) {
                array_push($names, ...$this->typeNames($type, $scope));
            }
            return $names;
        }

        if ($typeNode instanceof Identifier) {
            return [$typeNode->toString()];
        }

        $resolved = NodeNames::resolvedTypeName($typeNode, $scope);
        return $resolved === null ? [] : [$resolved];
    }

    private function isSerializableType(string $type): bool
    {
        if (in_array(strtolower($type), ['int', 'float', 'string', 'bool', 'array', 'null'], true)) {
            return true;
        }

        return $this->reflectionProvider->hasClass($type)
            && $this->reflectionProvider->getClass($type)->isEnum();
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

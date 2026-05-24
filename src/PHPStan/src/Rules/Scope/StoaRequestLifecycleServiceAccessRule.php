<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Scope;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\Scope\Scope as PhalanxScope;
use Phalanx\Stoa\ResponseSink;
use Phalanx\Stoa\StoaRequestDiagnostics;
use Phalanx\Stoa\StoaRequestResource;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<MethodCall>
 */
final class StoaRequestLifecycleServiceAccessRule implements Rule
{
    public const string MESSAGE =
        'Stoa request lifecycle services are framework-only; use RequestScope request accessors and $scope->ctx.';

    private const string IDENTIFIER = 'phalanx.scope.stoaLifecycleServiceAccess';

    /** @var array<class-string, true> */
    private const array LIFECYCLE_SERVICES = [
        ResponseSink::class => true,
        StoaRequestDiagnostics::class => true,
        StoaRequestResource::class => true,
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
        if (!$node instanceof MethodCall) {
            return [];
        }

        if (!$this->paths->shouldReport($scope->getFile()) || $this->paths->isInternal($scope->getFile())) {
            return [];
        }

        if ($this->isFrameworkNamespace($scope)) {
            return [];
        }

        if (NodeNames::calledMethodName($node) !== 'service') {
            return [];
        }

        if (!(new ObjectType(PhalanxScope::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        $service = $node->getArgs()[0]->value ?? null;
        if (!$service instanceof ClassConstFetch || NodeNames::classConstantName($service) !== 'class') {
            return [];
        }

        $serviceClass = NodeNames::classConstantClassName($service, $scope);
        if ($serviceClass === null || !isset(self::LIFECYCLE_SERVICES[$serviceClass])) {
            return [];
        }

        return RuleErrors::build(self::MESSAGE, self::IDENTIFIER, $node->getLine());
    }

    private function isFrameworkNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace() ?? '';

        return $namespace === 'Phalanx\\Stoa'
            || $namespace === 'Phalanx\\Hermes'
            || str_starts_with($namespace, 'Phalanx\\Stoa\\')
            || str_starts_with($namespace, 'Phalanx\\Hermes\\');
    }
}

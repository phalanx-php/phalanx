<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Scope;

use Phalanx\Http\ResponseSink;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\Scope\Scope as PhalanxScope;
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
final readonly class HttpRequestLifecycleServiceAccessRule implements Rule
{
    public const string MESSAGE =
        'Http request lifecycle services are framework-only; use RequestContext accessors instead.';

    private const string IDENTIFIER = 'phalanx.scope.httpLifecycleServiceAccess';

    /** @var array<class-string, true> */
    private const array LIFECYCLE_SERVICES = [
        ResponseSink::class => true,
        \Phalanx\Http\RequestDiagnostics::class => true,
        \Phalanx\Http\RequestResource::class => true,
    ];

    public function __construct(private PathPolicy $paths)
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

        if (!new ObjectType(PhalanxScope::class)->isSuperTypeOf($scope->getType($node->var))->yes()) {
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

        return $namespace === 'Phalanx\\Http'
            || $namespace === 'Phalanx\\WebSocket'
            || str_starts_with($namespace, 'Phalanx\\Http\\')
            || str_starts_with($namespace, 'Phalanx\\WebSocket\\');
    }
}

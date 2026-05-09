<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Runtime;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\ScopedRulePolicy;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Node>
 */
final class HookOwnershipRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.runtime.hookOwnership';

    /** @var list<string> */
    private const array RUNTIME_CLASSES = [
        'OpenSwoole\\Runtime',
        'Swoole\\Runtime',
    ];

    /** @var list<string> */
    private const array COROUTINE_CLASSES = [
        'OpenSwoole\\Coroutine',
        'Swoole\\Coroutine',
    ];

    private readonly ScopedRulePolicy $policy;

    /** @param list<string> $internalPaths */
    public function __construct(
        PathPolicy $paths,
        array $internalPaths = [],
    ) {
        $this->policy = new ScopedRulePolicy($paths, $internalPaths);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->policy->shouldReport($scope->getFile())) {
            return [];
        }

        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        if ($node instanceof ClassConstFetch) {
            return $this->processClassConstant($node, $scope);
        }

        if ($node instanceof ConstFetch) {
            return $this->processConstant($node);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        $class = NodeNames::calledClassName($node, $scope);
        $method = NodeNames::calledMethodName($node);

        if (in_array($class, self::RUNTIME_CLASSES, true) && $method === 'enableCoroutine') {
            return $this->error('runtime hook activation', $node->getLine());
        }

        if (in_array($class, self::COROUTINE_CLASSES, true) && $method === 'set') {
            return $this->error('coroutine hook options', $node->getLine());
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processClassConstant(ClassConstFetch $node, Scope $scope): array
    {
        $class = NodeNames::classConstantClassName($node, $scope);
        $constant = NodeNames::classConstantName($node);

        if (!in_array($class, self::RUNTIME_CLASSES, true) || $constant === null) {
            return [];
        }

        if (!str_starts_with($constant, 'HOOK_')) {
            return [];
        }

        return $this->error('runtime hook flag', $node->getLine());
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processConstant(ConstFetch $node): array
    {
        $constant = $node->name->toString();
        if (!str_starts_with($constant, 'SWOOLE_HOOK_')) {
            return [];
        }

        return $this->error('global runtime hook flag', $node->getLine());
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function error(string $shape, int $line): array
    {
        return RuleErrors::build(
            sprintf(
                'Aegis owns OpenSwoole %s; use RuntimePolicy, RuntimeHooks, or RuntimeCapability instead of configuring hooks in package code.',
                $shape,
            ),
            self::IDENTIFIER,
            $line,
        );
    }
}

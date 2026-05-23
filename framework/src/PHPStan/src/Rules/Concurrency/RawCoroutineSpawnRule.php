<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Concurrency;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\ScopedRulePolicy;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<StaticCall>
 */
final class RawCoroutineSpawnRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.openswoole.rawSpawn';

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
        return StaticCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof StaticCall || !$this->policy->shouldReport($scope->getFile())) {
            return [];
        }

        $class = NodeNames::calledClassName($node, $scope);
        $method = NodeNames::calledMethodName($node);

        if (!in_array($class, self::COROUTINE_CLASSES, true) || $method !== 'create') {
            return [];
        }

        return RuleErrors::build(
            'Raw coroutine spawning bypasses Phalanx scope/runtime truth; use $scope->go(), $scope->concurrent(), $scope->defer(), or a framework-owned spawn helper.',
            self::IDENTIFIER,
            $node->getLine(),
        );
    }
}

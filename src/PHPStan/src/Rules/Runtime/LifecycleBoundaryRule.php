<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Runtime;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\ScopedRulePolicy;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Node>
 */
final class LifecycleBoundaryRule implements Rule
{
    private const string TABLE_IDENTIFIER = 'phalanx.lifecycle.rawTableMutation';

    private const string ADAPTER_IDENTIFIER = 'phalanx.lifecycle.adapterBoundary';

    /** @var list<string> */
    private const array RESOURCE_MUTATORS = [
        'abort',
        'activate',
        'addLease',
        'annotate',
        'close',
        'fail',
        'open',
        'recordEvent',
        'release',
        'releaseLease',
        'upgrade',
    ];

    /** @var list<string> */
    private const array TABLES = [
        'resourceAnnotations',
        'resourceEdges',
        'resourceLeases',
        'resources',
    ];

    private readonly ScopedRulePolicy $policy;

    /** @param list<string> $internalPaths */
    public function __construct(PathPolicy $paths, array $internalPaths = [])
    {
        $this->policy = new ScopedRulePolicy($paths, $internalPaths);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->policy->shouldReport($scope->getFile())) {
            return [];
        }

        if ($node instanceof New_ && NodeNames::newClassName($node, $scope) === 'Swoole\\Table') {
            return RuleErrors::build(
                'Runtime owns lifecycle tables; use RuntimeMemory managed-resource APIs instead of constructing Swoole\\Table in package code.',
                self::TABLE_IDENTIFIER,
                $node->getStartLine(),
            );
        }

        if (!$node instanceof MethodCall) {
            return [];
        }

        $method = NodeNames::calledMethodName($node);
        if ($method === null) {
            return [];
        }

        if (in_array($method, ['set', 'del'], true) && self::isRuntimeTableFetch($node->var)) {
            return RuleErrors::build(
                'Runtime lifecycle table rows are managed-resource truth; use RuntimeMemory resources/events APIs instead of direct table mutation.',
                self::TABLE_IDENTIFIER,
                $node->getStartLine(),
            );
        }

        if (in_array($method, self::RESOURCE_MUTATORS, true) && self::isResourcesFetch($node->var)) {
            return RuleErrors::build(
                'Package code must use a lifecycle owner/adapter instead of calling RuntimeMemory->resources mutators directly.',
                self::ADAPTER_IDENTIFIER,
                $node->getStartLine(),
            );
        }

        return [];
    }

    private static function isResourcesFetch(Node\Expr $expr): bool
    {
        return $expr instanceof PropertyFetch
            && self::propertyName($expr) === 'resources';
    }

    private static function isRuntimeTableFetch(Node\Expr $expr): bool
    {
        if (!$expr instanceof PropertyFetch || !in_array(self::propertyName($expr), self::TABLES, true)) {
            return false;
        }

        return $expr->var instanceof PropertyFetch
            && self::propertyName($expr->var) === 'tables';
    }

    private static function propertyName(PropertyFetch $fetch): ?string
    {
        if (!$fetch->name instanceof Node\Identifier) {
            return null;
        }

        return $fetch->name->toString();
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Runtime;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\ScopedRulePolicy;
use Phalanx\Runtime\Memory\ManagedResourceRegistry;
use Phalanx\Runtime\Memory\RuntimeCounters;
use Phalanx\Runtime\QueryScope;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<MethodCall>
 */
final class RuntimeSidLiteralRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.lifecycle.typedRuntimeIds';

    private const string RESOURCE_MESSAGE = 'Runtime resources use typed RuntimeResourceId enums; do not pass bare string resource IDs in package code.';

    private const string ANNOTATION_MESSAGE = 'Runtime annotations use typed RuntimeAnnotationId enums; do not pass bare string annotation IDs in package code.';

    private const string EVENT_MESSAGE = 'Runtime events use typed RuntimeEventId enums; do not pass bare string event IDs in package code.';

    private const string COUNTER_MESSAGE = 'Runtime counters use typed RuntimeCounterId enums; do not pass bare string counter IDs in package code.';

    private const array SID_ARGUMENT_BY_METHOD = [
        'all' => 0,
        'annotate' => 1,
        'decr' => 0,
        'get' => 0,
        'incr' => 0,
        'liveCount' => 0,
        'open' => 0,
        'recordEvent' => 1,
        'stateCounts' => 0,
        'tryIncr' => 0,
        'upgrade' => 1,
    ];

    /** @var array<string, string> */
    private const array SID_ARGUMENT_NAME_BY_METHOD = [
        'all' => 'type',
        'annotate' => 'key',
        'decr' => 'name',
        'get' => 'name',
        'incr' => 'name',
        'liveCount' => 'type',
        'open' => 'type',
        'recordEvent' => 'type',
        'stateCounts' => 'type',
        'tryIncr' => 'name',
        'upgrade' => 'toType',
    ];

    /** @var list<string> */
    private const array RESOURCE_METHODS = [
        'all',
        'annotate',
        'liveCount',
        'open',
        'recordEvent',
        'upgrade',
    ];

    /** @var list<string> */
    private const array QUERY_METHODS = [
        'all',
        'liveCount',
        'stateCounts',
    ];

    /** @var list<string> */
    private const array COUNTER_METHODS = [
        'decr',
        'get',
        'incr',
        'tryIncr',
    ];

    private readonly ScopedRulePolicy $policy;

    /** @param list<string> $internalPaths */
    public function __construct(PathPolicy $paths, array $internalPaths = [])
    {
        $this->policy = new ScopedRulePolicy($paths, $internalPaths);
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->policy->shouldReport($scope->getFile())) {
            return [];
        }

        $method = NodeNames::calledMethodName($node);
        if ($method === null || !array_key_exists($method, self::SID_ARGUMENT_BY_METHOD)) {
            return [];
        }

        if (!self::isRuntimeLifecycleSurface($node, $scope, $method)) {
            return [];
        }

        $arg = self::argument(
            $node->args,
            self::SID_ARGUMENT_BY_METHOD[$method],
            self::SID_ARGUMENT_NAME_BY_METHOD[$method],
        );
        if (!$arg?->value instanceof String_) {
            return [];
        }

        if (!preg_match('/^[a-z][a-z0-9_-]*\\.[a-z0-9_.-]+$/', $arg->value->value)) {
            return [];
        }

        return RuleErrors::build(
            self::messageFor($method),
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }

    private static function messageFor(string $method): string
    {
        return match ($method) {
            'annotate' => self::ANNOTATION_MESSAGE,
            'recordEvent' => self::EVENT_MESSAGE,
            'decr', 'get', 'incr', 'tryIncr' => self::COUNTER_MESSAGE,
            default => self::RESOURCE_MESSAGE,
        };
    }

    private static function isRuntimeLifecycleSurface(MethodCall $call, Scope $scope, string $method): bool
    {
        $receiver = $scope->getType($call->var);

        if (
            in_array($method, self::RESOURCE_METHODS, true)
            && (new ObjectType(ManagedResourceRegistry::class))->isSuperTypeOf($receiver)->yes()
        ) {
            return true;
        }

        if (
            in_array($method, self::QUERY_METHODS, true)
            && (new ObjectType(QueryScope::class))->isSuperTypeOf($receiver)->yes()
        ) {
            return true;
        }

        return in_array($method, self::COUNTER_METHODS, true)
            && (new ObjectType(RuntimeCounters::class))->isSuperTypeOf($receiver)->yes();
    }

    /** @param array<Arg|\PhpParser\Node\VariadicPlaceholder> $args */
    private static function argument(array $args, int $offset, string $name): ?Arg
    {
        $position = 0;
        foreach ($args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            if ($arg->name !== null) {
                if ($arg->name->toString() === $name) {
                    return $arg;
                }

                continue;
            }

            if ($position === $offset) {
                return $arg;
            }

            $position++;
        }

        return null;
    }
}

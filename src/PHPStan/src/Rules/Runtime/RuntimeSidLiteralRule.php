<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Runtime;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\ScopedRulePolicy;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<MethodCall>
 */
final class RuntimeSidLiteralRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.lifecycle.typedRuntimeIds';

    private const array SID_ARGUMENT_BY_METHOD = [
        'all' => 0,
        'annotate' => 1,
        'liveCount' => 0,
        'open' => 0,
        'recordEvent' => 1,
        'stateCounts' => 0,
        'upgrade' => 1,
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

        $arg = self::argument($node->args, self::SID_ARGUMENT_BY_METHOD[$method]);
        if (!$arg?->value instanceof String_) {
            return [];
        }

        if (!preg_match('/^[a-z][a-z0-9_-]*\\.[a-z0-9_.-]+$/', $arg->value->value)) {
            return [];
        }

        return RuleErrors::build(
            'Runtime lifecycle resources, events, annotations, and counters use typed Sid enums; do not pass bare string lifecycle IDs in package code.',
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }

    /** @param array<Arg|\PhpParser\Node\VariadicPlaceholder> $args */
    private static function argument(array $args, int $offset): ?Arg
    {
        $position = 0;
        foreach ($args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            if ($arg->name !== null) {
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

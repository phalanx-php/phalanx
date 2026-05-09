<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Scope;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\StaticCall;
use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\RuleErrors;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<StaticCall>
 */
final class TaskFactoryStaticClosureOnlyRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.scope.staticClosureOnly';

    public function __construct(private readonly PathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!$node instanceof StaticCall || !$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        $class = NodeNames::calledClassName($node, $scope);
        $method = NodeNames::calledMethodName($node);
        if ($class !== 'Phalanx\\Task\\Task' || !in_array($method, ['named', 'of'], true)) {
            return [];
        }

        $closure = $method === 'named'
            ? ($node->args[1]->value ?? null)
            : ($node->args[0]->value ?? null);

        if ((!$closure instanceof ArrowFunction && !$closure instanceof Closure) || $closure->static) {
            return [];
        }

        return RuleErrors::build(
            sprintf(
                'Closure passed to Task::%s() must be static so it cannot capture $this in a long-running coroutine.',
                $method,
            ),
            self::IDENTIFIER,
            $closure->getLine(),
        );
    }
}

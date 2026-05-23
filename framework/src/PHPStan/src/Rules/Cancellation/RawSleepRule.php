<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Cancellation;

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
final class RawSleepRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.cancellation.rawSleep';

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
        if ($this->paths->isInternal($scope->getFile()) || !$this->paths->shouldReport($scope->getFile())) {
            return [];
        }

        $class = NodeNames::calledClassName($node, $scope);
        $method = NodeNames::calledMethodName($node);
        if (!in_array($class, ['OpenSwoole\\Coroutine', 'Swoole\\Coroutine'], true)) {
            return [];
        }

        if (!in_array($method, ['sleep', 'usleep'], true)) {
            return [];
        }

        return RuleErrors::build(
            sprintf(
                'Use $scope->delay() instead of %s::%s() inside scoped task code; raw sleep does not observe the Phalanx cancellation token.',
                $class,
                $method,
            ),
            self::IDENTIFIER,
            $node->getLine(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Testing;

use Phalanx\PHPStan\Support\NodeNames;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<FuncCall>
 */
final class NoRawTestIoRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.noRawIo';

    private const array RAW_TEMP_FUNCTIONS = [
        'sys_get_temp_dir',
        'tempnam',
        'tmpfile',
    ];

    public function __construct(private readonly TestingPathPolicy $paths)
    {
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile(), self::IDENTIFIER)) {
            return [];
        }

        $function = NodeNames::functionName($node, $scope);
        if ($function === null) {
            return [];
        }

        $function = strtolower(ltrim($function, '\\'));
        if (in_array($function, self::RAW_TEMP_FUNCTIONS, true)) {
            return $this->report($node, "{$function}()");
        }

        if ($function !== 'fopen') {
            return [];
        }

        $target = $node->args[0]->value ?? null;
        if (!$target instanceof String_) {
            return [];
        }

        $path = $target->value;
        if (!in_array($path, ['php://temp', 'php://memory', '/dev/null'], true)) {
            return [];
        }

        return $this->report($node, "fopen('{$path}')");
    }

    /** @return list<IdentifierRuleError> */
    private function report(FuncCall $node, string $shape): array
    {
        return RuleErrors::build(
            "High-level Phalanx tests should not use raw test IO via {$shape}; "
            . 'use Phalanx\\Stream\\Stream buffers or Phalanx\\Testing\\TempWorkspace.',
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }
}

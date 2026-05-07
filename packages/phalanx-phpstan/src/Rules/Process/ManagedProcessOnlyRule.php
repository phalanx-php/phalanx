<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Process;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
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
final class ManagedProcessOnlyRule implements Rule
{
    private const IDENTIFIER = 'phalanx.process.managedOnly';

    /** @var list<string> */
    private const FORBIDDEN_FUNCTIONS = [
        'proc_open',
        'proc_close',
        'proc_get_status',
        'proc_terminate',
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

        if ($node instanceof FuncCall) {
            return $this->processFunction($node, $scope);
        }

        if ($node instanceof New_) {
            return $this->processNew($node, $scope);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processFunction(FuncCall $node, Scope $scope): array
    {
        $function = NodeNames::functionName($node, $scope);
        if ($function === null || !in_array(strtolower($function), self::FORBIDDEN_FUNCTIONS, true)) {
            return [];
        }

        return $this->error($function . '()', $node->getLine());
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processNew(New_ $node, Scope $scope): array
    {
        $class = NodeNames::newClassName($node, $scope);
        if ($class !== 'Symfony\\Component\\Process\\Process') {
            return [];
        }

        return $this->error('Symfony Process construction', $node->getLine());
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function error(string $shape, int $line): array
    {
        return RuleErrors::build(
            sprintf(
                'Subprocesses must be Aegis-managed resources; use Phalanx\System\StreamingProcess instead of %s in package code.',
                $shape,
            ),
            self::IDENTIFIER,
            $line,
        );
    }
}

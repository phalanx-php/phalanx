<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command\Config;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;
use Phalanx\Themis\ConfigCatalog;
use Phalanx\Themis\ConfigValidator;
use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;
use Phalanx\Themis\ValidationPurpose;
use Phalanx\Themis\ValidationResult;

/**
 * Validates all registered config classes against the current environment.
 *
 * Groups issues by severity (Error / Warning / Info) and prints a hint for
 * each issue when one is available. Exits 1 when validation would block boot;
 * exits 0 otherwise.
 *
 * Options:
 *   --strict   Treat warnings as boot-blockers (mirrors ValidationContext::strict).
 */
final class ConfigDoctorCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $catalog = $scope->service(ConfigCatalog::class);
        $output = $scope->service(StreamOutput::class);
        $validator = $scope->service(ConfigValidator::class);

        $strict = $scope->options->flag('strict');
        $ctx = new ValidationContext(strict: $strict, purpose: ValidationPurpose::Doctor);
        $result = $validator->validate($catalog->roots, $ctx);

        self::renderResult($output, $result);

        return $result->blocksBoot ? 1 : 0;
    }

    private static function renderResult(StreamOutput $output, ValidationResult $result): void
    {
        if ($result->issues === []) {
            $output->persist('Config validation passed. No issues found.');
            return;
        }

        $byLevel = self::groupByLevel($result->issues);

        foreach ([IssueLevel::Error, IssueLevel::Warning, IssueLevel::Info] as $level) {
            $issues = $byLevel[$level->name] ?? [];

            if ($issues === []) {
                continue;
            }

            $output->persist(self::levelHeader($level));

            foreach ($issues as $issue) {
                $line = '  [' . $issue->code . '] ' . $issue->message;

                if ($issue->envKey !== null) {
                    $line .= '  (env: ' . $issue->envKey . ')';
                }

                $output->persist($line);

                if ($issue->hint !== null) {
                    $output->persist('    hint: ' . $issue->hint);
                }
            }
        }

        if ($result->blocksBoot) {
            $output->persist('');
            $output->persist('Validation failed: environment is not ready to boot.');
        }
    }

    /**
     * @param list<Issue> $issues
     * @return array<string, list<Issue>>
     */
    private static function groupByLevel(array $issues): array
    {
        $grouped = [];

        foreach ($issues as $issue) {
            $grouped[$issue->level->name][] = $issue;
        }

        return $grouped;
    }

    private static function levelHeader(IssueLevel $level): string
    {
        return match ($level) {
            IssueLevel::Error => 'Errors:',
            IssueLevel::Warning => 'Warnings:',
            IssueLevel::Info => 'Info:',
        };
    }
}

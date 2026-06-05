<?php

declare(strict_types=1);

namespace Phalanx\Console\Command\Config;

use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Command\Opt;
use Phalanx\Console\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;
use Phalanx\Config\ConfigCatalog;
use Phalanx\Config\ConfigValidator;
use Phalanx\Config\Issue;
use Phalanx\Config\IssueLevel;
use Phalanx\Config\ValidationContext;
use Phalanx\Config\ValidationPurpose;
use Phalanx\Config\ValidationResult;

final class ConfigDoctorCommand implements Scopeable, DescribesCommand
{
    public function __invoke(CommandContext $ctx): int
    {
        $catalog = $ctx->service(ConfigCatalog::class);
        $output = $ctx->service(StreamOutput::class);
        $validator = $ctx->service(ConfigValidator::class);

        $strict = $ctx->options->flag('strict');
        $validationCtx = new ValidationContext(strict: $strict, purpose: ValidationPurpose::Doctor);
        $result = $validator->validate($catalog->roots, $validationCtx);

        self::renderResult($output, $result);

        return $result->blocksBoot ? 1 : 0;
    }

    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Validate the current environment against all registered config classes.',
            options: [
                Opt::flag(name: 'strict', desc: 'Treat warnings as boot-blockers.'),
            ],
        );
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

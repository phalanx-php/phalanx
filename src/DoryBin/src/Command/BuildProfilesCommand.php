<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Command;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\Table;
use Phalanx\DoryBin\BuildProfileDefinition;
use Phalanx\DoryBin\BuildProfileRegistry;
use Phalanx\Task\Scopeable;

final class BuildProfilesCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'List available build profiles',
            options: [
                Opt::value('format', 'f', 'Output format', default: 'table'),
            ],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $registry = $ctx->service(BuildProfileRegistry::class);

        $profiles = $registry->all();

        $headers = ['Profile', 'Extensions', 'Packages', 'Description'];
        $rows = array_map(static fn(BuildProfileDefinition $def): array => [
            $def->profile->value,
            (string) count($def->allExtensions()),
            (string) count($def->phalanxPackages),
            $def->description,
        ], $profiles);

        $widths = Table::computeWidths($headers, $rows, $output->width());
        $table = new Table(Theme::default());

        $output->persist($table->header($headers, $widths));

        foreach ($rows as $row) {
            $output->persist($table->row($row, $widths));
        }

        $output->persist($table->footer($widths));

        return 0;
    }
}

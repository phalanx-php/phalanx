<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command\Build;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\Table;
use Phalanx\Dory\Build\BuildProfileDefinition;
use Phalanx\Dory\Build\BuildProfileRegistry;
use Phalanx\Task\Scopeable;

final class BuildProfilesCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $registry = new BuildProfileRegistry(BuildProfileRegistry::defaultProfileDir());

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

    public static function resolveProfileDir(): string
    {
        return BuildProfileRegistry::defaultProfileDir();
    }
}

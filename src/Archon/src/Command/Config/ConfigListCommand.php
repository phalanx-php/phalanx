<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command\Config;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;
use Phalanx\Themis\CatalogNode;
use Phalanx\Themis\ConfigCatalog;
use Phalanx\Themis\ConfigEntry;
use ReflectionClass;

final class ConfigListCommand implements Scopeable, DescribesCommand
{
    public function __invoke(CommandContext $ctx): int
    {
        $catalog = $ctx->service(ConfigCatalog::class);
        $output = $ctx->service(StreamOutput::class);

        $nodes = $catalog->tree();

        if ($nodes === []) {
            $output->persist('No config classes registered.');
            return 0;
        }

        foreach ($nodes as $node) {
            self::renderNode($output, $node, 0);
        }

        return 0;
    }

    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'List all registered config classes and their env keys.',
        );
    }

    private static function renderNode(StreamOutput $output, CatalogNode $node, int $depth): void
    {
        $indent = str_repeat('  ', $depth);
        $shortName = (new ReflectionClass($node->type))->getShortName();
        $output->persist($indent . $shortName . ' (' . $node->type . ')');

        foreach ($node->entries as $entry) {
            $output->persist($indent . '  ' . self::formatEntry($entry));
        }

        foreach ($node->children as $child) {
            self::renderNode($output, $child, $depth + 1);
        }
    }

    private static function formatEntry(ConfigEntry $entry): string
    {
        $required = $entry->required ? '[required]' : '[optional]';
        $default = self::resolveDefault($entry);
        $desc = $entry->description !== null ? '  ' . $entry->description : '';

        return sprintf(
            '%s  %s  %s  default=%s%s',
            $entry->envKey,
            $entry->type,
            $required,
            $default,
            $desc,
        );
    }

    private static function resolveDefault(ConfigEntry $entry): string
    {
        if ($entry->secret) {
            return '[secret]';
        }

        return $entry->default ?? '(none)';
    }
}

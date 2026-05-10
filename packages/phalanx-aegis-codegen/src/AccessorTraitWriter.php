<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Codegen;

use RuntimeException;

/**
 * Emits the Phalanx\Testing\Generated\TestAppAccessors trait from a list of
 * LensMetadata. The output is deterministic and git-stable: imports are
 * alphabetized, accessors are sorted alphabetically, and every regeneration
 * produces byte-identical output for the same input.
 */
final class AccessorTraitWriter
{
    public const string TARGET_TRAIT = 'TestAppAccessors';
    public const string TARGET_NAMESPACE = 'Phalanx\\Testing\\Generated';

    /** @param list<LensMetadata> $lenses */
    public function render(array $lenses): string
    {
        $useStatements = self::collectUseStatements($lenses);
        $accessors = self::renderAccessors($lenses);

        $body = "<?php\n\n";
        $body .= "declare(strict_types=1);\n\n";
        $body .= 'namespace ' . self::TARGET_NAMESPACE . ";\n";

        if ($useStatements !== '') {
            $body .= "\n" . $useStatements;
        }

        $body .= "\n/**\n";
        $body .= " * Generated typed-property accessors for TestApp.\n";
        $body .= " *\n";
        $body .= " * Emitted by phalanx-aegis-codegen on composer post-autoload-dump.\n";
        $body .= " * Edits are overwritten on the next dump.\n";
        $body .= " */\n";
        $body .= 'trait ' . self::TARGET_TRAIT . "\n";
        $body .= "{\n";

        if ($accessors !== '') {
            $body .= $accessors;
        }

        $body .= "}\n";

        return $body;
    }

    /** @param list<LensMetadata> $lenses */
    public function write(array $lenses, string $targetFile): void
    {
        $directory = dirname($targetFile);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory {$directory} for generated accessors.");
        }

        $contents = $this->render($lenses);

        if (file_put_contents($targetFile, $contents) === false) {
            throw new RuntimeException("Unable to write generated accessors to {$targetFile}.");
        }
    }

    /**
     * Returns a map of FQCN => import alias (alias equals short name unless there is a collision).
     *
     * @param list<LensMetadata> $lenses
     * @return array<string,string> fqcn => alias
     */
    private static function buildAliasMap(array $lenses): array
    {
        $fqcns = array_unique(array_column($lenses, 'lensClass'));

        // First pass: record which short names appear more than once.
        $shortNameCount = [];
        foreach ($fqcns as $fqcn) {
            $short = self::shortName($fqcn);
            $shortNameCount[$short] = ($shortNameCount[$short] ?? 0) + 1;
        }

        $aliasMap = [];
        $usedAliases = [];

        foreach ($fqcns as $fqcn) {
            $short = self::shortName($fqcn);

            if ($shortNameCount[$short] === 1 && !isset($usedAliases[$short])) {
                $aliasMap[$fqcn] = $short;
                $usedAliases[$short] = true;
                continue;
            }

            // Collision: build a unique alias from the namespace segments.
            $parts = explode('\\', $fqcn);
            $alias = implode('', array_slice($parts, -2));
            $base = $alias;
            $i = 2;
            while (isset($usedAliases[$alias])) {
                $alias = $base . $i++;
            }

            $aliasMap[$fqcn] = $alias;
            $usedAliases[$alias] = true;
        }

        return $aliasMap;
    }

    /** @param list<LensMetadata> $lenses */
    private static function collectUseStatements(array $lenses): string
    {
        $aliasMap = self::buildAliasMap($lenses);

        $lines = [];
        foreach ($aliasMap as $fqcn => $alias) {
            $short = self::shortName($fqcn);
            $lines[] = $alias === $short
                ? 'use ' . $fqcn . ';'
                : 'use ' . $fqcn . ' as ' . $alias . ';';
        }

        sort($lines);

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<LensMetadata> $lenses */
    private static function renderAccessors(array $lenses): string
    {
        $aliasMap = self::buildAliasMap($lenses);

        $sorted = $lenses;
        usort($sorted, static fn(LensMetadata $a, LensMetadata $b): int => $a->accessor <=> $b->accessor);

        $blocks = [];

        foreach ($sorted as $lens) {
            $alias = $aliasMap[$lens->lensClass] ?? self::shortName($lens->lensClass);
            $blocks[] = "    public {$alias} \${$lens->accessor} {\n"
                . "        get => \$this->lens({$alias}::class);\n"
                . "    }\n";
        }

        return implode("\n", $blocks);
    }

    private static function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }
}

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
    public const string TARGET_NAMESPACE = 'Phalanx\\Testing\\Generated';
    public const string TARGET_TRAIT = 'TestAppAccessors';

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

    /** @param list<LensMetadata> $lenses */
    private static function collectUseStatements(array $lenses): string
    {
        $imports = [];

        foreach ($lenses as $lens) {
            $imports[$lens->lensClass] = true;
        }

        $names = array_keys($imports);
        sort($names);

        if ($names === []) {
            return '';
        }

        $lines = array_map(static fn(string $fqcn): string => 'use ' . $fqcn . ';', $names);

        return implode("\n", $lines) . "\n";
    }

    /** @param list<LensMetadata> $lenses */
    private static function renderAccessors(array $lenses): string
    {
        $sorted = $lenses;
        usort($sorted, static fn(LensMetadata $a, LensMetadata $b): int => $a->accessor <=> $b->accessor);

        $blocks = [];

        foreach ($sorted as $lens) {
            $shortName = self::shortName($lens->lensClass);
            $blocks[] = "    public {$shortName} \${$lens->accessor} {\n"
                . "        get => \$this->lens({$shortName}::class);\n"
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

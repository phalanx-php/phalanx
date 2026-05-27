<?php

declare(strict_types=1);

namespace Phalanx\Themis;

final class EnvExampleGenerator
{
    /**
     * @param list<class-string<Config>> $configs
     * @param array<string, string> $knownValues
     */
    public function generate(array $configs, array $knownValues = []): string
    {
        $known = $knownValues;
        $reflection = new ConfigReflection();

        $seen = [];
        $lines = [];
        foreach ($configs as $config) {
            foreach ($reflection->describe($config) as $definition) {
                if ($definition->entries === []) {
                    continue;
                }

                $lines[] = '# ' . $definition->type;
                foreach ($definition->entries as $entry) {
                    if (isset($seen[$entry->envKey])) {
                        continue;
                    }

                    $seen[$entry->envKey] = true;
                    $value = $known[$entry->envKey] ?? $entry->example ?? $entry->default ?? '';
                    $lines[] = $entry->envKey . '=' . $value;
                    unset($known[$entry->envKey]);
                }
                $lines[] = '';
            }
        }

        if ($known !== []) {
            $lines[] = '# Custom';
            foreach ($known as $key => $value) {
                $lines[] = $key . '=' . $value;
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }
}

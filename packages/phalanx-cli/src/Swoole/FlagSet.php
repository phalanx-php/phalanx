<?php

declare(strict_types=1);

namespace Phalanx\Cli\Swoole;

final class FlagSet
{
    /**
     * @param list<OpenSwooleFlag> $flags
     * @param array<string, string> $values
     */
    public function __construct(
        private(set) array $flags,
        private(set) array $values = [],
    ) {
    }

    public static function defaults(): self
    {
        return new self(array_values(array_filter(
            OpenSwooleFlag::cases(),
            static fn (OpenSwooleFlag $flag): bool => $flag->defaultEnabled(),
        )));
    }

    /** @return list<string> */
    public function toPieArgs(): array
    {
        $args = [];

        foreach ($this->flags as $flag) {
            if ($flag->needsValue()) {
                if (isset($this->values[$flag->value])) {
                    $args[] = '--' . $flag->value . '=' . $this->values[$flag->value];
                }
                continue;
            }

            $args[] = '--' . $flag->value;
        }

        return $args;
    }

    /** @return list<SystemDependencyHint> */
    public function systemDependenciesFor(Platform $platform): array
    {
        $hints = [];
        $seen = [];

        foreach ($this->flags as $flag) {
            foreach ($flag->systemDependencies() as $hint) {
                if ($hint->platform !== $platform) {
                    continue;
                }

                if (isset($seen[$hint->packageName])) {
                    continue;
                }

                $seen[$hint->packageName] = true;
                $hints[] = $hint;
            }
        }

        return $hints;
    }

    public function isEmpty(): bool
    {
        return $this->flags === [];
    }

    public function contains(OpenSwooleFlag $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}

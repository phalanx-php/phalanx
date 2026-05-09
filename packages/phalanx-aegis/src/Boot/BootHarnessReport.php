<?php

declare(strict_types=1);

namespace Phalanx\Boot;

class BootHarnessReport
{
    /**
     * @param list<BootEvaluationEntry> $passed
     * @param list<BootEvaluationEntry> $warned
     * @param list<BootEvaluationEntry> $failed
     */
    public function __construct(
        private(set) array $passed = [],
        private(set) array $warned = [],
        private(set) array $failed = [],
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warned !== [];
    }

    public function isClean(): bool
    {
        return $this->failed === [] && $this->warned === [];
    }

    /**
     * Renders a human-readable summary for CannotBootException and the
     * --doctor command surface.
     */
    public function render(): string
    {
        if ($this->isClean()) {
            return 'Boot harness: all requirements satisfied.';
        }

        $lines = [];

        if ($this->failed !== []) {
            $lines[] = 'Boot failures:';
            foreach ($this->failed as $entry) {
                $lines[] = sprintf('  - [%s] %s', $entry->requirement->kind, $entry->evaluation->message);
                if ($entry->evaluation->remediation !== null) {
                    $lines[] = sprintf('      -> %s', $entry->evaluation->remediation);
                }
            }
        }

        if ($this->warned !== []) {
            if ($this->failed !== []) {
                $lines[] = '';
            }
            $lines[] = 'Warnings:';
            foreach ($this->warned as $entry) {
                $lines[] = sprintf('  - [%s] %s', $entry->requirement->kind, $entry->evaluation->message);
                if ($entry->evaluation->remediation !== null) {
                    $lines[] = sprintf('      -> %s', $entry->evaluation->remediation);
                }
            }
        }

        return implode("\n", $lines);
    }
}

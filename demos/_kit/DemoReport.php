<?php

declare(strict_types=1);

namespace Phalanx\Demos\Kit;

/**
 * Single shared primitive replacing the per-demo "check tally + printf +
 * exit code" pattern. Records each check as it runs (printed live so demos
 * remain readable when invoked interactively), then dump() emits a final
 * pass/fail line and returns the appropriate exit code.
 */
final class DemoReport
{
    /**
     * Report-only demo entry, for demos that don't need to boot an Aegis
     * kernel (Athena, Stoa, Archon all build their own facades that
     * compose Aegis internally — booting a parallel kernel just for lens
     * access would be wasted work).
     *
     * Returns the Symfony Runtime-shape outer closure: receives context,
     * calls the body with (report, AppContext), yields the report's exit
     * code.
     *
     * @param \Closure(self, \Phalanx\Boot\AppContext): void $body
     */
    public static function demo(string $title, \Closure $body): \Closure
    {
        return static fn (array $context): \Closure =>
            static function () use ($context, $title, $body): int {
                $report = new self($title);
                $body($report, new \Phalanx\Boot\AppContext($context));

                return $report->exitCode();
            };
    }

    /** @var list<array{label: string, ok: bool}> */
    private array $records = [];

    private bool $headerPrinted = false;

    private bool $preempted = false;

    public function __construct(private(set) string $title)
    {
    }

    public function record(string $label, bool $ok, string $detail = ''): bool
    {
        $this->records[] = ['label' => $label, 'ok' => $ok];
        $this->printHeaderOnce();
        printf("  %s  %s\n", $ok ? 'ok    ' : 'FAIL  ', $label);

        if (!$ok && $detail !== '') {
            foreach (explode("\n", rtrim($detail)) as $line) {
                printf("      %s\n", $line);
            }
        }

        return $ok;
    }

    public function note(string $line): void
    {
        $this->printHeaderOnce();
        printf("  %s\n", $line);
    }

    /**
     * Render a "cannot run" preflight failure — used when a demo can't
     * execute because of missing credentials, an absent local service, or
     * a similar precondition. Returns 0: a documented cannot-run is a
     * valid pass per the demo contract, not a failure.
     */
    public function cannotRun(string $reason, string $fix): int
    {
        $this->preempted = true;
        $this->printHeaderOnce();
        printf("  cannot run\n");
        printf("    reason: %s\n", $reason);
        printf("    fix:    %s\n", $fix);

        return 0;
    }

    public function exitCode(): int
    {
        if ($this->preempted) {
            return 0;
        }

        $this->printHeaderOnce();

        $okCount = 0;
        $failCount = 0;
        foreach ($this->records as $record) {
            if ($record['ok']) {
                $okCount++;
            } else {
                $failCount++;
            }
        }

        $total = $okCount + $failCount;
        printf("\n  %s  %d/%d\n", $failCount === 0 ? 'OK' : 'FAIL', $okCount, $total);

        return $failCount === 0 ? 0 : 1;
    }

    private function printHeaderOnce(): void
    {
        if ($this->headerPrinted) {
            return;
        }

        $this->headerPrinted = true;
        printf("%s\n%s\n", $this->title, str_repeat('=', strlen($this->title)));
    }
}

<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Theme;

/**
 * Stateless spinner renderer. Caller owns the tick counter and the timer.
 *
 * Caller pattern:
 *   $tick = 0;
 *   $timer = Loop::addPeriodicTimer(0.08, static function () use ($spinner, $output, &$tick): void {
 *       $output->update($spinner->frame($tick++, 'Working...'));
 *   });
 *   // ... async work ...
 *   Loop::cancelTimer($timer);
 *   $output->clear();
 *
 * The timer MUST be cancelled before any persist() output follows — an
 * orphaned periodic timer will run indefinitely, preventing loop exit.
 */
final class Spinner
{
    public const array DOTS    = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    public const array BRAILLE = ['⣾', '⣽', '⣻', '⢿', '⡿', '⣟', '⣯', '⣷'];
    public const array LINE    = ['-', '\\', '|', '/'];
    public const array ARC     = ['◜', '◠', '◝', '◞', '◡', '◟'];

    public function __construct(
        private readonly Theme $theme,
        private readonly array $frames = self::DOTS,
    ) {}

    public function frame(int $tick, string $label = ''): string
    {
        $char   = $this->frames[$tick % count($this->frames)];
        $styled = $this->theme->accent->apply($char);
        return $label !== '' ? "{$styled} {$label}" : $styled;
    }
}

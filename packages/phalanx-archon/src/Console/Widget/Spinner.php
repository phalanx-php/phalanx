<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Widget;

use Phalanx\Archon\Console\Style\Theme;

/**
 * Stateless spinner renderer. Caller owns the tick counter and the subscription.
 *
 * Caller pattern (scope-owned periodic):
 *   $tick = 0;
 *   $sub = $scope->periodic(0.08, static function () use ($spinner, $output, &$tick): void {
 *       $output->update($spinner->frame($tick++, 'Working...'));
 *   });
 *   // ... async work ...
 *   $sub->cancel();
 *   $output->clear();
 *
 * The subscription cancels automatically on scope dispose; explicit cancel()
 * before any persist() output is required to avoid a torn render between
 * the final tick and the persisted line.
 */
final class Spinner
{
    public const array DOTS    = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    public const array BRAILLE = ['⣾', '⣽', '⣻', '⢿', '⡿', '⣟', '⣯', '⣷'];
    public const array LINE    = ['-', '\\', '|', '/'];
    public const array ARC     = ['◜', '◠', '◝', '◞', '◡', '◟'];

    /**
     * @param list<string> $frames
     */
    public function __construct(
        private readonly Theme $theme,
        private readonly array $frames = self::DOTS,
    ) {
    }

    public function frame(int $tick, string $label = ''): string
    {
        $char   = $this->frames[$tick % count($this->frames)];
        $styled = $this->theme->accent->apply($char);
        return $label !== '' ? "{$styled} {$label}" : $styled;
    }
}

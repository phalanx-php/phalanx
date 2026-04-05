<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Closure;
use Phalanx\Console\Style\Theme;
use Phalanx\Console\Widget\Spinner;
use React\Promise\PromiseInterface;

/**
 * Async-capable search prompt. Combines a text input with a filterable results list.
 *
 * The $search closure receives the current query and returns either:
 *   - list<string>        for synchronous/in-memory filtering
 *   - PromiseInterface    for async search (database, API, etc.)
 *
 * When the search returns a Promise, the key loop is intentionally suspended until
 * the Promise resolves. Keys typed during that window queue in RawInput::$pending
 * and are processed in order after loop() restarts. This prevents out-of-order
 * state mutations if two searches race.
 *
 * State:
 *   $highlighted === null → focus is in the text input
 *   $highlighted !== null → focus is in the results list
 *
 * Bindings (text input focus):
 *   printable  insert char, trigger search
 *   backspace  delete from query, trigger search
 *   up/down    enter results list ($highlighted = 0 or last)
 *   enter      submit if results available and highlighted
 *
 * Bindings (results list focus):
 *   up    move up (if at top, return focus to text input)
 *   down  move down
 *   enter submit $matches[$highlighted]
 *   printable  exit list, append to query, trigger search
 */
final class SearchInput extends BasePrompt
{
    private string $query       = '';
    private int $queryCursor    = 0;
    private ?array $matches     = [];
    private ?int $highlighted   = null;
    private int $spinnerTick    = 0;
    private Spinner $spinner;

    public function __construct(
        Theme $theme,
        private readonly string $label,
        private readonly Closure $search,
        private readonly int $scroll = 5,
        private readonly string $placeholder = 'Type to search…',
    ) {
        parent::__construct($theme);
        $this->spinner = new Spinner($theme, Spinner::DOTS);
    }

    protected function handleKey(string $key): void
    {
        if ($this->highlighted === null) {
            $this->handleQueryKey($key);
        } else {
            $this->handleListKey($key);
        }
    }

    protected function renderActive(): string
    {
        $width      = $this->innerWidth();
        $innerWidth = $width - 4;
        $title      = $this->state === 'error'
            ? $this->theme->error->apply($this->label)
            : $this->theme->accent->apply($this->label);

        $queryLine = $this->renderQueryLine($innerWidth - 4);

        if ($this->state === 'searching') {
            $spinLine = '  ' . $this->spinner->frame($this->spinnerTick++, 'Searching…');
            return $this->buildFrame("{$queryLine}\n{$spinLine}", $title, $this->label, $width);
        }

        if ($this->matches === null || $this->matches === []) {
            $emptyLine = $this->matches === []
                ? '  ' . $this->theme->muted->apply('No results')
                : '';
            $body = $emptyLine !== '' ? "{$queryLine}\n{$emptyLine}" : $queryLine;
            return $this->buildFrame($body . $this->hintLine(), $title, $this->label, $width);
        }

        $scroll  = max(1, min($this->scroll, $this->height() - 8));
        $lines   = [$queryLine, $this->theme->border->apply('  ' . str_repeat('─', $innerWidth - 2))];

        $visible = array_slice($this->matches, 0, $scroll);
        foreach ($visible as $i => $match) {
            $isActive  = $i === $this->highlighted;
            $prefix    = $isActive ? $this->theme->accent->apply('  › ') : '    ';
            $padded    = mb_str_pad((string) $match, $innerWidth - 6);
            $lines[]   = $prefix . ($isActive ? $this->theme->accent->apply($padded) : $padded);
        }

        return $this->buildFrame(implode("\n", $lines) . $this->hintLine(), $title, $this->label, $width);
    }

    protected function renderAnswered(): string
    {
        $submitted = $this->highlighted !== null
            ? ($this->matches[$this->highlighted] ?? '')
            : $this->query;

        return $this->buildFrame(
            '  ' . $this->theme->accent->apply((string) $submitted),
            $this->theme->muted->apply($this->label),
            $this->label,
            $this->innerWidth(),
            answered: true,
        );
    }

    #[\Override]
    protected function hints(): string
    {
        return '↑↓ navigate results  enter confirm';
    }

    protected function defaultValue(): mixed
    {
        return null;
    }

    private function handleQueryKey(string $key): void
    {
        match (true) {
            $key === 'enter' && $this->highlighted !== null && $this->matches !== null
                => $this->submit($this->matches[$this->highlighted]),
            $key === 'enter' && $this->query !== ''
                => $this->submit($this->query),
            $key === 'down' && $this->matches !== []
                => $this->highlighted = 0,
            $key === 'space'
                => $this->insertQuery(' '),
            $key === 'backspace'
                => $this->deleteQueryLeft(),
            mb_strlen($key) === 1 && mb_ord($key) >= 32
                => $this->insertQuery($key),
            default => null,
        };

        if (in_array($key, ['backspace', 'space'], true) || (mb_strlen($key) === 1 && mb_ord($key) >= 32)) {
            $this->triggerSearch();
        }
    }

    private function handleListKey(string $key): void
    {
        $count = $this->matches !== null ? count($this->matches) : 0;

        match ($key) {
            'up'    => $this->highlighted = $this->highlighted <= 0
                ? ($this->highlighted = null)
                : $this->highlighted - 1,
            'down'  => $this->highlighted = min($count - 1, ($this->highlighted ?? -1) + 1),
            'enter' => $this->highlighted !== null && $this->matches !== null
                ? $this->submit($this->matches[$this->highlighted])
                : null,
            default => $this->exitListAndInsert($key),
        };
    }

    private function insertQuery(string $char): void
    {
        $chars = mb_str_split($this->query);
        array_splice($chars, $this->queryCursor, 0, [$char]);
        $this->query = implode('', $chars);
        $this->queryCursor++;
    }

    private function deleteQueryLeft(): void
    {
        if ($this->queryCursor === 0) {
            return;
        }
        $chars = mb_str_split($this->query);
        array_splice($chars, $this->queryCursor - 1, 1);
        $this->query = implode('', $chars);
        $this->queryCursor--;
    }

    private function exitListAndInsert(string $key): void
    {
        $this->highlighted = null;
        if (mb_strlen($key) === 1 && mb_ord($key) >= 32) {
            $this->insertQuery($key);
            $this->triggerSearch();
        }
    }

    private function triggerSearch(): void
    {
        $this->state       = 'searching';
        $this->matches     = null;
        $this->highlighted = null;

        $result = ($this->search)($this->query);

        if ($result instanceof PromiseInterface) {
            $this->loopOwned = true;
            $this->render();  // show spinner only for genuine async search
            /**
             * Non-static: search may resolve after loop() yields. WeakReference
             * risks GC before resolution, leaving the prompt frozen with no key handler.
             */
            $result->then(function (array $matches): void {
                $this->loopOwned = false;
                $this->matches   = $matches;
                $this->state     = 'active';
                $this->render();
                $this->loop();
            });
            // loop() intentionally NOT called here — .then() resumes it after search.
        } else {
            $this->matches = (array) $result;
            $this->state   = 'active';
            // loop().then() will call renderFrame() — no intermediate render needed
        }
    }

    private function renderQueryLine(int $maxWidth): string
    {
        if ($this->query === '') {
            return '  ' . $this->theme->muted->apply($this->placeholder);
        }

        $chars = mb_str_split($this->query);
        if ($this->queryCursor >= count($chars)) {
            $chars[] = ' ';
        }
        $chars[$this->queryCursor] = "\033[7m{$chars[$this->queryCursor]}\033[27m";

        return '  ' . implode('', $chars);
    }

}

<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Element;

use Phalanx\Theatron\Tui\Core\Component;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use Phalanx\Theatron\Tui\Tdom\Style;

final class MountElement implements Renderable
{
    /** computed: mount placeholders inherit paint styling from the resolved mounted renderable. */
    public ?Style $style { get => null; }

    /** @var array<string, mixed> */
    private(set) array $props;

    /**
     * @template T of Component
     * @param class-string<T> $component
     * @param array<int|string, mixed> $props
     */
    public function __construct(
        private(set) string $component,
        array $props = [],
    ) {
        $this->props = self::namedProps($props);
    }

    /**
     * @param array<int|string, mixed> $props
     * @return array<string, mixed>
     */
    private static function namedProps(array $props): array
    {
        $named = [];

        foreach ($props as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Component props must be passed as named arguments.');
            }

            $named[$key] = $value;
        }

        return $named;
    }
}

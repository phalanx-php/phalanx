<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Scope\Scope;
use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Tui\Core\Component;
use Phalanx\Tui\Tui\Core\Screen;
use Phalanx\Tui\Tui\Reactive\DirtyBatch;
use Phalanx\Tui\Tui\Reactive\SignalRegistry;
use Phalanx\Tui\Tui\Tdom\Element\ColumnElement;
use Phalanx\Tui\Tui\Tdom\Element\GridElement;
use Phalanx\Tui\Tui\Tdom\Element\MountElement;
use Phalanx\Tui\Tui\Tdom\Element\PanelElement;
use Phalanx\Tui\Tui\Tdom\Element\RowElement;
use Phalanx\Tui\Tui\Tdom\Element\StatusLineElement;
use Phalanx\Tui\Tui\Tdom\Renderable;
use ReflectionClass;
use ReflectionNamedType;

final class MountSystem
{
    /** @var list<MountedComponent> */
    private array $mounted = [];

    /** @var array<class-string, object> */
    private array $provided = [];

    private int $providedVersion = 0;

    /** @var array<class-string, ReflectionClass<object>> */
    private array $reflectionCache = [];

    /** @var list<MountedScreen> */
    private array $mountedScreens = [];

    /**
     * @var list<array{
     *     owner:int,
     *     next:int,
     *     pending:array<string, array{signature:string,mounted:MountedComponent,previous:?MountedComponent}>
     * }>
     */
    private array $frames = [];

    /** @var array<string, MountedComponent> */
    private array $slots = [];

    /** @var array<string, string> */
    private array $slotSignatures = [];

    public function __construct(
        private(set) Scope $scope,
        private ?TaskScope $taskScope = null,
        private ?SignalRegistry $registry = null,
    ) {
        $this->taskScope ??= $scope instanceof TaskScope ? $scope : null;

        if ($this->taskScope !== null) {
            $self = $this;
            $this->taskScope->onDispose(static function () use ($self): void {
                $self->disposeAll();
            });
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param T $instance
     */
    public function provide(string $class, object $instance): void
    {
        if (!$instance instanceof $class) {
            throw new \RuntimeException(sprintf('Expected instance of %s, got %s.', $class, $instance::class));
        }

        $this->provided[$class] = $instance;
        $this->providedVersion++;
    }

    /**
     * @template T of Component
     * @param class-string<T> $component
     */
    public function mountComponent(string $component, mixed ...$params): MountedComponent
    {
        $namedParams = self::extractNamedParams($params);
        $slot = $this->nextSlot();

        if ($slot !== null) {
            [$frameIndex, $owner, $index] = $slot;
            $key = self::slotKey($owner, $index);
            $signature = $component . ':' . $this->providedVersion . ':' . self::signature($namedParams);
            $pending = $this->frames[$frameIndex]['pending'][$key] ?? null;
            $existing = $this->slots[$key] ?? null;

            if ($pending !== null && $pending['signature'] === $signature) {
                return $pending['mounted'];
            }

            if (
                $existing !== null
                && !$existing->isDisposed
                && ($this->slotSignatures[$key] ?? null) === $signature
            ) {
                return $existing;
            }

            $mounted = $this->mountFresh($component, $namedParams, activate: false);
            $this->frames[$frameIndex]['pending'][$key] = [
                'signature' => $signature,
                'mounted' => $mounted,
                'previous' => $existing,
            ];

            return $mounted;
        }

        return $this->mountFresh($component, $namedParams);
    }

    public function enterFrame(object $owner): void
    {
        $this->frames[] = [
            'owner' => spl_object_id($owner),
            'next' => 0,
            'pending' => [],
        ];
    }

    public function leaveFrame(object $owner, bool $commit = true): void
    {
        $frame = array_pop($this->frames);
        $ownerId = spl_object_id($owner);

        if ($frame === null || $frame['owner'] !== $ownerId) {
            throw new \RuntimeException('Mount frame stack is unbalanced.');
        }

        if (!$commit) {
            foreach ($frame['pending'] as $replacement) {
                $replacement['mounted']->dispose();
                $this->forgetMounted($replacement['mounted']);
            }

            return;
        }

        try {
            foreach ($frame['pending'] as $replacement) {
                $this->activateMounted($replacement['mounted']);
            }
        } catch (\Throwable $e) {
            foreach ($frame['pending'] as $replacement) {
                $replacement['mounted']->dispose();
                $this->forgetMounted($replacement['mounted']);
            }

            throw $e;
        }

        foreach ($frame['pending'] as $key => $replacement) {
            if ($replacement['previous'] !== null) {
                $replacement['previous']->dispose();
                $this->forgetMounted($replacement['previous']);
            }

            $this->slots[$key] = $replacement['mounted'];
            $this->slotSignatures[$key] = $replacement['signature'];
        }

        $this->disposeUnusedSlots($ownerId, $frame['next']);
    }

    /**
     * @template T of Screen
     * @param class-string<T> $screen
     */
    public function mountScreen(string $screen, mixed ...$params): MountedScreen
    {
        $namedParams = self::extractNamedParams($params);
        /** @var Screen $instance */
        $instance = $this->resolveInstance($screen, $namedParams);
        $dirty = new DirtyBatch();

        $scanResult = SignalScanner::scan($instance, $dirty, $namedParams, $this->registry);
        $mounted = new MountedScreen($instance, $dirty, $scanResult);
        $this->mountedScreens[] = $mounted;
        $this->activateScreen($mounted);

        return $mounted;
    }

    /** @return list<MountedComponent> */
    public function mounted(): array
    {
        return $this->mounted;
    }

    public function disposeAll(): void
    {
        $mounted = $this->mounted;
        $screens = $this->mountedScreens;
        $this->mounted = [];
        $this->mountedScreens = [];
        $this->slots = [];
        $this->slotSignatures = [];
        $this->frames = [];

        foreach ($screens as $screen) {
            $screen->dispose();
        }

        foreach ($mounted as $component) {
            $component->dispose();
        }
    }

    public function disposeOwnedSlots(object $owner): void
    {
        $this->disposeSlotsForOwner(spl_object_id($owner));
    }

    public function hasDirtyOwnedSlots(object $owner): bool
    {
        $visited = [];

        return $this->hasDirtySlotsForOwner(spl_object_id($owner), $visited);
    }

    public function resolve(Renderable $node): Renderable
    {
        if ($node instanceof MountElement) {
            return $this->mountComponent($node->component, ...$node->props);
        }

        if ($node instanceof ColumnElement) {
            $children = $this->resolveList($node->children);

            return $children === $node->children ? $node : new ColumnElement($children, $node->style);
        }

        if ($node instanceof RowElement) {
            $children = $this->resolveList($node->children);

            return $children === $node->children ? $node : new RowElement($children, $node->style);
        }

        if ($node instanceof GridElement) {
            $children = $this->resolveList($node->children);

            return $children === $node->children ? $node : new GridElement($node->columns, $children, $node->style);
        }

        if ($node instanceof PanelElement) {
            $child = $this->resolve($node->child);

            return $child === $node->child ? $node : new PanelElement($node->title, $child, $node->style);
        }

        if ($node instanceof StatusLineElement) {
            $sections = $this->resolveList($node->sections);

            return $sections === $node->sections ? $node : new StatusLineElement($sections, $node->style);
        }

        return $node;
    }

    private static function slotKey(int $owner, int $slot): string
    {
        return $owner . ':' . $slot;
    }

    /** @param array<string, mixed> $params */
    private static function signature(array $params): string
    {
        ksort($params);

        return json_encode(self::signatureValue($params), JSON_THROW_ON_ERROR);
    }

    private static function signatureValue(mixed $value): mixed
    {
        if (is_object($value)) {
            return ['object' => $value::class, 'id' => spl_object_id($value)];
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::signatureValue($item);
            }

            if (array_is_list($result)) {
                return $result;
            }

            ksort($result);

            return $result;
        }

        if (is_resource($value)) {
            return ['resource' => get_resource_id($value)];
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>
     */
    private static function extractNamedParams(array $params): array
    {
        $named = [];
        foreach ($params as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Component props must be passed as named arguments.');
            }

            $named[$key] = $value;
        }

        return $named;
    }

    /**
     * @template T of Component
     * @param class-string<T> $component
     * @param array<string, mixed> $namedParams
     */
    private function mountFresh(string $component, array $namedParams, bool $activate = true): MountedComponent
    {
        /** @var Component $instance */
        $instance = $this->resolveInstance($component, $namedParams);
        $dirty = new DirtyBatch();

        $scanResult = SignalScanner::scan($instance, $dirty, $namedParams, $this->registry);
        $mounted = new MountedComponent($instance, $dirty, $scanResult);
        $this->mounted[] = $mounted;

        if ($activate) {
            $this->activateMounted($mounted);
        }

        return $mounted;
    }

    private function activateMounted(MountedComponent $mounted): void
    {
        if ($this->taskScope !== null) {
            $mounted->activate($this->taskScope);
        }
    }

    private function activateScreen(MountedScreen $mounted): void
    {
        if ($this->taskScope !== null) {
            $mounted->activate($this->taskScope);
        }
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $namedParams
     */
    private function resolveInstance(string $class, array $namedParams): object
    {
        $ref = $this->reflectionCache[$class] ??= new ReflectionClass($class);
        $constructor = $ref->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $namedParams)) {
                $args[$name] = $namedParams[$name];
                continue;
            }

            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                /** @var class-string $typeName */
                $typeName = $type->getName();

                if (isset($this->provided[$typeName])) {
                    $args[$name] = $this->provided[$typeName];
                    continue;
                }

                try {
                    $service = $this->scope->service($typeName);
                    if ($service instanceof $typeName) {
                        $args[$name] = $service;
                        continue;
                    }
                } catch (ServiceNotFoundException) {
                }
            }

            if ($param->isDefaultValueAvailable()) {
                continue;
            }
        }

        return new $class(...$args);
    }

    /** @return array{int,int,int}|null */
    private function nextSlot(): ?array
    {
        if ($this->frames === []) {
            return null;
        }

        $index = array_key_last($this->frames);
        $owner = $this->frames[$index]['owner'];
        $slot = $this->frames[$index]['next'];
        $this->frames[$index]['next']++;

        return [$index, $owner, $slot];
    }

    private function disposeUnusedSlots(int $owner, int $used): void
    {
        $prefix = $owner . ':';
        $unused = [];

        foreach ($this->slots as $key => $mounted) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $slot = (int) substr($key, strlen($prefix));
            if ($slot < $used) {
                continue;
            }

            $unused[$key] = $mounted;
        }

        foreach ($unused as $key => $mounted) {
            $mounted->dispose();
            $this->forgetMounted($mounted);
            unset($this->slots[$key], $this->slotSignatures[$key]);
        }
    }

    private function disposeSlotsForOwner(int $owner): void
    {
        $prefix = $owner . ':';
        $owned = [];

        foreach ($this->slots as $key => $mounted) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $owned[$key] = $mounted;
        }

        foreach ($owned as $key => $mounted) {
            unset($this->slots[$key], $this->slotSignatures[$key]);
            $mounted->dispose();
            $this->forgetMounted($mounted);
        }
    }

    private function forgetMounted(MountedComponent $component): void
    {
        $remaining = [];

        foreach ($this->mounted as $mounted) {
            if ($mounted !== $component) {
                $remaining[] = $mounted;
            }
        }

        $this->mounted = $remaining;
    }

    /** @param array<int, true> $visited */
    private function hasDirtySlotsForOwner(int $owner, array &$visited): bool
    {
        $prefix = $owner . ':';

        foreach ($this->slots as $key => $mounted) {
            if (!str_starts_with($key, $prefix) || $mounted->isDisposed) {
                continue;
            }

            if ($mounted->isDirty) {
                return true;
            }

            $mountedId = spl_object_id($mounted);
            if (isset($visited[$mountedId])) {
                continue;
            }

            $visited[$mountedId] = true;

            if ($this->hasDirtySlotsForOwner($mountedId, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Renderable> $nodes
     * @return list<Renderable>
     */
    private function resolveList(array $nodes): array
    {
        $resolved = [];

        foreach ($nodes as $node) {
            $resolved[] = $this->resolve($node);
        }

        return $resolved;
    }
}

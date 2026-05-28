<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp;

use Phalanx\Swoole\Mvp\Runtime\Compiler;
use Phalanx\Swoole\Mvp\Runtime\CompileException;
use Phalanx\Swoole\Mvp\Runtime\Dispatcher;
use Phalanx\Swoole\Mvp\Runtime\KeyedLockRegistry;
use Phalanx\Swoole\Mvp\Runtime\TaskMetadata;
use Phalanx\Swoole\Mvp\Service\Container;
use Phalanx\Swoole\Mvp\Service\Services;

final class Application
{
    private readonly Services $services;

    /** @var list<class-string> */
    private array $tasks = [];

    /** @var array<class-string, TaskMetadata>|null */
    private ?array $compiled = null;

    private ?Dispatcher $dispatcher = null;

    public function __construct()
    {
        $this->services = new Services();
    }

    public function services(): Services
    {
        return $this->services;
    }

    /**
     * @param class-string ...$classes
     */
    public function registerTasks(string ...$classes): self
    {
        foreach ($classes as $c) {
            if (! in_array($c, $this->tasks, true)) {
                $this->tasks[] = $c;
            }
        }
        return $this;
    }

    public function compile(): self
    {
        if ($this->compiled !== null) {
            return $this;
        }
        $this->compiled = Compiler::compile($this->tasks, $this->services->resources);
        return $this;
    }

    public function boot(): self
    {
        if ($this->compiled === null) {
            throw new CompileException('Application::boot() called before compile().');
        }
        $container = new Container($this->services->resources);
        $registry = new KeyedLockRegistry();
        $this->dispatcher = new Dispatcher($this->compiled, $container, $registry);
        return $this;
    }

    public function dispatcher(): Dispatcher
    {
        return $this->dispatcher ?? throw new CompileException('Application not booted.');
    }

    /**
     * @return array<class-string, TaskMetadata>
     */
    public function metadata(): array
    {
        return $this->compiled ?? throw new CompileException('Application not compiled.');
    }
}

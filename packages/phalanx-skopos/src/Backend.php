<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

final class Backend
{
    private string $type;
    private ?string $command = null;
    private ?string $readyPattern = null;
    /** @var list<string> */
    private array $watchPaths = [];
    /** @var list<string> */
    private array $watchExtensions = ['php'];
    /** @var array<string, string> */
    private array $env = [];
    private ?string $cwd = null;

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function php(string $command = 'php -S localhost:8000 -t public'): self
    {
        $b = new self('php');
        $b->command = $command;
        $b->readyPattern = '/Development Server|Server running|started/i';
        $b->watchPaths = ['app/', 'routes/', 'config/', 'src/'];
        return $b;
    }

    public static function phalanx(string $command): self
    {
        $b = new self('phalanx');
        $b->command = $command;
        $b->readyPattern = '/listening on/i';
        $b->watchPaths = ['src/'];
        return $b;
    }

    public static function node(string $command): self
    {
        $b = new self('node');
        $b->command = $command;
        $b->readyPattern = '/listening|ready|started/i';
        return $b;
    }

    public static function custom(string $command, ?string $readyPattern = null): self
    {
        $b = new self('custom');
        $b->command = $command;
        $b->readyPattern = $readyPattern;
        return $b;
    }

    public function ready(string $pattern): self
    {
        $clone = clone $this;
        $clone->readyPattern = $pattern;
        return $clone;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $extensions
     */
    public function watch(array $paths, array $extensions = ['php']): self
    {
        $clone = clone $this;
        $clone->watchPaths = $paths;
        $clone->watchExtensions = array_values($extensions);
        return $clone;
    }

    /** @param array<string, string> $env */
    public function env(array $env): self
    {
        $clone = clone $this;
        $clone->env = $env;
        return $clone;
    }

    public function cwd(string $cwd): self
    {
        $clone = clone $this;
        $clone->cwd = $cwd;
        return $clone;
    }

    public function resolve(): Process
    {
        $process = Process::named($this->type . '-server')
            ->command($this->command ?? throw new \RuntimeException('Backend command not set'))
            ->asServer()
            ->env($this->env);

        if ($this->readyPattern !== null) {
            $process = $process->ready($this->readyPattern);
        }

        if ($this->watchPaths !== []) {
            $process = $process->watch($this->watchPaths, $this->watchExtensions);
        }

        if ($this->cwd !== null) {
            $process = $process->cwd($this->cwd);
        }

        return $process;
    }
}

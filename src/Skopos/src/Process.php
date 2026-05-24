<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

final class Process
{
    private(set) string $name;
    private(set) string $command = '';
    private(set) ?string $cwd = null;
    /** @var array<string, string> */
    private(set) array $env = [];
    private(set) ReadinessProbe $readinessProbe;
    private(set) bool $isServer = false;
    /** @var list<string> */
    private(set) array $watchPaths = [];
    /** @var list<string> */
    private(set) array $watchExtensions = ['php'];

    private function __construct(string $name)
    {
        $this->name = $name;
        $this->readinessProbe = ReadinessProbe::immediate();
    }

    public static function named(string $name): self
    {
        return new self($name);
    }

    public function command(string $command): self
    {
        $clone = clone $this;
        $clone->command = $command;
        return $clone;
    }

    public function cwd(string $cwd): self
    {
        $clone = clone $this;
        $clone->cwd = $cwd;
        return $clone;
    }

    /** @param array<string, string> $env */
    public function env(array $env): self
    {
        $clone = clone $this;
        $clone->env = $env;
        return $clone;
    }

    public function ready(string $pattern): self
    {
        $clone = clone $this;
        $clone->readinessProbe = ReadinessProbe::outputMatches($pattern);
        return $clone;
    }

    public function readinessProbe(ReadinessProbe $probe): self
    {
        $clone = clone $this;
        $clone->readinessProbe = $probe;
        return $clone;
    }

    public function asServer(): self
    {
        $clone = clone $this;
        $clone->isServer = true;
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
}

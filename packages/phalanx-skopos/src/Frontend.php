<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

final class Frontend
{
    private string $type;
    private string $entry = 'resources/js/app.js';
    private string $outdir = 'public/assets/js';
    private ?string $publicPath = null;
    private bool $splitting = true;
    private bool $sourcemap = true;
    private bool $minify = false;
    private ?Css $css = null;
    /** @var array<string, string> */
    private array $env = [];
    private ?string $customCommand = null;
    private ?string $reloadPattern = null;

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function react(?string $entry = null): self
    {
        $f = new self('react');
        if ($entry !== null) {
            $f->entry = $entry;
        } else {
            $f->entry = 'resources/js/app.jsx';
        }
        return $f;
    }

    public static function vue(?string $entry = null): self
    {
        $f = new self('vue');
        if ($entry !== null) {
            $f->entry = $entry;
        }
        return $f;
    }

    public static function svelte(?string $entry = null): self
    {
        $f = new self('svelte');
        if ($entry !== null) {
            $f->entry = $entry;
        }
        return $f;
    }

    public static function vanilla(?string $entry = null): self
    {
        $f = new self('vanilla');
        if ($entry !== null) {
            $f->entry = $entry;
        }
        return $f;
    }

    public static function custom(string $command, ?string $reloadPattern = null): self
    {
        $f = new self('custom');
        $f->customCommand = $command;
        $f->reloadPattern = $reloadPattern;
        return $f;
    }

    public function entry(string $entry): self
    {
        $clone = clone $this;
        $clone->entry = $entry;
        return $clone;
    }

    public function outdir(string $outdir): self
    {
        $clone = clone $this;
        $clone->outdir = $outdir;
        return $clone;
    }

    public function publicPath(string $publicPath): self
    {
        $clone = clone $this;
        $clone->publicPath = $publicPath;
        return $clone;
    }

    public function splitting(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->splitting = $enabled;
        return $clone;
    }

    public function sourcemap(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->sourcemap = $enabled;
        return $clone;
    }

    public function minify(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->minify = $enabled;
        return $clone;
    }

    public function css(Css $css): self
    {
        $clone = clone $this;
        $clone->css = $css;
        return $clone;
    }

    /** @param array<string, string> $env */
    public function env(array $env): self
    {
        $clone = clone $this;
        $clone->env = $env;
        return $clone;
    }

    /** @return list<Process> */
    public function resolve(string $cwd): array
    {
        $processes = [];

        if ($this->type === 'custom') {
            $process = Process::named('frontend')
                ->command($this->customCommand ?? throw new \RuntimeException('Custom frontend command not set'))
                ->cwd($cwd)
                ->env($this->env);

            if ($this->reloadPattern !== null) {
                $process = $process->reloadOn($this->reloadPattern);
            }

            $processes[] = $process;
        } else {
            $processes[] = $this->buildJsProcess($cwd);
        }

        if ($this->css !== null) {
            $cssProcess = $this->css->resolve();

            if ($cssProcess !== null) {
                $processes[] = $cssProcess->cwd($cwd);
            }
        }

        return $processes;
    }

    private function buildJsProcess(string $cwd): Process
    {
        return match ($this->type) {
            'react', 'vanilla' => $this->buildCliProcess($cwd),
            'vue', 'svelte' => $this->buildPluginProcess($cwd),
            default => throw new \RuntimeException("Unknown frontend type: {$this->type}"),
        };
    }

    private function buildCliProcess(string $cwd): Process
    {
        $bin = BinaryResolver::bun($this->env);
        $parts = [$bin, 'build', $this->entry, '--outdir', $this->outdir, '--target', 'browser'];

        if ($this->splitting) {
            $parts[] = '--splitting';
        }

        if ($this->sourcemap) {
            $parts[] = '--sourcemap=external';
        }

        if ($this->minify) {
            $parts[] = '--minify';
        }

        if ($this->publicPath !== null) {
            $parts[] = '--public-path';
            $parts[] = $this->publicPath;
        }

        $parts[] = '--watch';

        $reloadPattern = '/\\d+.*\\btransformed\\b/';

        return Process::named("bun-{$this->type}")
            ->command(implode(' ', $parts))
            ->cwd($cwd)
            ->env($this->env)
            ->ready($reloadPattern)
            ->reloadOn($reloadPattern);
    }

    private function buildPluginProcess(string $cwd): Process
    {
        $bin = BinaryResolver::bun($this->env);
        $script = BuildScript::generate(
            $this->type,
            $this->entry,
            $this->outdir,
            $this->splitting,
            $this->sourcemap,
            $this->minify,
        );

        $scriptPath = BuildScript::write($cwd, $script);
        $reloadPattern = '/files transformed/';

        return Process::named("bun-{$this->type}")
            ->command("{$bin} run --watch {$scriptPath}")
            ->cwd($cwd)
            ->env($this->env)
            ->ready($reloadPattern)
            ->reloadOn($reloadPattern);
    }
}

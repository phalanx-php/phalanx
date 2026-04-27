<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

final class Css
{
    private string $type;
    private ?string $input = null;
    private ?string $output = null;
    private bool $watch = true;
    private bool $minify = false;
    /** @var array<string, string> */
    private array $env = [];
    private ?string $reloadPattern = null;
    private ?string $customCommand = null;

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function tailwind(?string $input = null, ?string $output = null): self
    {
        $css = new self('tailwind');
        $css->input = $input ?? 'resources/css/app.css';
        $css->output = $output ?? 'public/assets/css/app.css';
        $css->reloadPattern = '/Done in/';
        return $css;
    }

    public static function sass(string $input, string $output): self
    {
        $css = new self('sass');
        $css->input = $input;
        $css->output = $output;
        $css->reloadPattern = '/Compiled/';
        return $css;
    }

    public static function unocss(?string $output = null): self
    {
        $css = new self('unocss');
        $css->output = $output ?? 'public/assets/css/uno.css';
        $css->reloadPattern = '/generated/i';
        return $css;
    }

    public static function postcss(string $input, string $output): self
    {
        $css = new self('postcss');
        $css->input = $input;
        $css->output = $output;
        $css->reloadPattern = '/output written/i';
        return $css;
    }

    public static function none(): self
    {
        return new self('none');
    }

    public static function custom(string $command, ?string $reloadPattern = null): self
    {
        $css = new self('custom');
        $css->customCommand = $command;
        $css->reloadPattern = $reloadPattern;
        return $css;
    }

    public function output(string $path): self
    {
        $clone = clone $this;
        $clone->output = $path;
        return $clone;
    }

    public function input(string $path): self
    {
        $clone = clone $this;
        $clone->input = $path;
        return $clone;
    }

    public function watch(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->watch = $enabled;
        return $clone;
    }

    public function minify(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->minify = $enabled;
        return $clone;
    }

    /** @param array<string, string> $env */
    public function env(array $env): self
    {
        $clone = clone $this;
        $clone->env = $env;
        return $clone;
    }

    public function resolve(): ?Process
    {
        if ($this->type === 'none') {
            return null;
        }

        $command = $this->buildCommand();
        $process = Process::named($this->type . '-css')
            ->command($command)
            ->env($this->env);

        if ($this->reloadPattern !== null) {
            $process = $process
                ->ready($this->reloadPattern)
                ->reloadOn($this->reloadPattern);
        }

        return $process;
    }

    private function buildCommand(): string
    {
        return match ($this->type) {
            'tailwind' => $this->buildTailwindCommand(),
            'sass' => $this->buildSassCommand(),
            'unocss' => $this->buildUnocssCommand(),
            'postcss' => $this->buildPostcssCommand(),
            'custom' => $this->customCommand ?? throw new \RuntimeException('Custom CSS command not set'),
            default => throw new \RuntimeException("Unknown CSS type: {$this->type}"),
        };
    }

    private function buildTailwindCommand(): string
    {
        $bin = BinaryResolver::tailwindcss();
        $parts = [$bin, '-i', $this->input ?? 'resources/css/app.css', '-o', $this->output ?? 'public/assets/css/app.css'];

        if ($this->watch) {
            $parts[] = '--watch';
        }

        if ($this->minify) {
            $parts[] = '--minify';
        }

        return implode(' ', $parts);
    }

    private function buildSassCommand(): string
    {
        $input = $this->input ?? throw new \RuntimeException('Sass requires an input file');
        $output = $this->output ?? throw new \RuntimeException('Sass requires an output file');

        $bin = BinaryResolver::sass();
        $parts = [$bin];

        if ($this->watch) {
            $parts[] = '--watch';
        }

        $parts[] = $input . ':' . $output;

        if ($this->minify) {
            $parts[] = '--style=compressed';
        }

        return implode(' ', $parts);
    }

    private function buildUnocssCommand(): string
    {
        $bin = BinaryResolver::bun();
        $parts = [$bin, 'x', 'unocss'];

        if ($this->watch) {
            $parts[] = '--watch';
        }

        if ($this->output !== null) {
            $parts[] = '--out-file';
            $parts[] = $this->output;
        }

        return implode(' ', $parts);
    }

    private function buildPostcssCommand(): string
    {
        $bin = BinaryResolver::bun();
        $parts = [$bin, 'x', 'postcss', $this->input ?? 'resources/css/app.css', '-o', $this->output ?? 'public/assets/css/app.css'];

        if ($this->watch) {
            $parts[] = '--watch';
        }

        return implode(' ', $parts);
    }
}

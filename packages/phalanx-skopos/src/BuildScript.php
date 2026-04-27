<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

final class BuildScript
{
    public static function generate(
        string $framework,
        string $entry,
        string $outdir,
        bool $splitting = true,
        bool $sourcemap = true,
        bool $minify = false,
    ): string {
        $plugin = match ($framework) {
            'vue' => self::vuePlugin(),
            'svelte' => self::sveltePlugin(),
            default => throw new \RuntimeException("BuildScript does not support framework '{$framework}'"),
        };

        $splittingStr = $splitting ? 'true' : 'false';
        $sourcemapStr = $sourcemap ? '"external"' : 'false';
        $minifyStr = $minify ? 'true' : 'false';

        return <<<TS
        {$plugin}

        const result = await Bun.build({
          entrypoints: ["{$entry}"],
          outdir: "{$outdir}",
          splitting: {$splittingStr},
          sourcemap: {$sourcemapStr},
          minify: {$minifyStr},
          target: "browser",
          plugins: [plugin],
        });

        if (!result.success) {
          for (const log of result.logs) {
            console.error(log);
          }
          process.exit(1);
        }

        console.log(`\${result.outputs.length} files transformed`);
        TS;
    }

    public static function write(string $basePath, string $content): string
    {
        $dir = $basePath . '/.skopos';

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $path = $dir . '/build.ts';
        file_put_contents($path, $content);

        return $path;
    }

    private static function vuePlugin(): string
    {
        return <<<'TS'
        import vuePlugin from "bun-plugin-vue";
        const plugin = vuePlugin();
        TS;
    }

    private static function sveltePlugin(): string
    {
        return <<<'TS'
        import sveltePlugin from "bun-plugin-svelte";
        const plugin = sveltePlugin();
        TS;
    }
}

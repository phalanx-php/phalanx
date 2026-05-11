<?php

declare(strict_types=1);

namespace Phalanx\Cli\Scaffold;

use Symfony\Component\Console\Output\OutputInterface;

final class ProjectGenerator
{
    public function __invoke(string $name, string $directory, OutputInterface $output): void
    {
        $namespace = self::toNamespace($name);
        $variables = [
            'name' => $name,
            'namespace' => $namespace,
            'namespace_escaped' => str_replace('\\', '\\\\', $namespace),
        ];

        self::writeFile($directory . '/composer.json', self::composerTemplate(), $variables, $output, $directory);
        self::writeFile($directory . '/public/index.php', self::indexTemplate(), $variables, $output, $directory);
        self::writeFile($directory . '/routes.php', self::routesTemplate(), $variables, $output, $directory);
        self::writeFile($directory . '/src/Routes/Home.php', self::homeTemplate(), $variables, $output, $directory);
        self::writeFile($directory . '/.gitignore', self::gitignoreTemplate(), $variables, $output, $directory);
    }

    private static function toNamespace(string $name): string
    {
        $parts = explode('-', $name);
        $parts = array_map(static fn (string $p): string => ucfirst($p), $parts);

        return 'App\\' . implode('', $parts);
    }

    /** @param array<string, string> $variables */
    private static function writeFile(
        string $path,
        string $template,
        array $variables,
        OutputInterface $output,
        string $projectDir,
    ): void {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        $content = TemplateRenderer::render($template, $variables);

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }

        $relative = ltrim(substr($path, strlen($projectDir)), '/');
        $output->writeln("  Created {$relative}");
    }

    private static function composerTemplate(): string
    {
        return <<<'TEMPLATE'
{
    "name": "app/{{name}}",
    "description": "A Phalanx application",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": "^8.4",
        "ext-openswoole": "^26.0",
        "phalanx-php/aegis": "^0.6",
        "phalanx-php/stoa": "^0.6"
    },
    "autoload": {
        "psr-4": {
            "{{namespace_escaped}}\\": "src/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
TEMPLATE;
    }

    private static function indexTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Stoa\Stoa;

Stoa::starting()
    ->routes(__DIR__ . '/../routes.php')
    ->listen('127.0.0.1:8080')
    ->run();
TEMPLATE;
    }

    private static function routesTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

use {{namespace}}\Routes\Home;
use Phalanx\Stoa\RouteGroup;

return RouteGroup::of([
    'GET /' => Home::class,
]);
TEMPLATE;
    }

    private static function homeTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

namespace {{namespace}}\Routes;

use Phalanx\Scope\Scope;

final class Home
{
    public function __invoke(Scope $scope): array
    {
        return ['message' => 'Welcome to Phalanx'];
    }
}
TEMPLATE;
    }

    private static function gitignoreTemplate(): string
    {
        return <<<'TEMPLATE'
/vendor/
.env
TEMPLATE;
    }
}

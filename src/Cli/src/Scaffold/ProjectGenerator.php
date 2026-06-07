<?php

declare(strict_types=1);

namespace Phalanx\Cli\Scaffold;

use Symfony\Component\Console\Output\OutputInterface;

final class ProjectGenerator
{
    public function __invoke(
        string $name,
        string $directory,
        OutputInterface $output,
        ProjectType $type = ProjectType::Api,
    ): void {
        $namespace = self::toNamespace($name);
        $variables = [
            'name' => $name,
            'namespace' => $namespace,
            'namespace_escaped' => str_replace('\\', '\\\\', $namespace),
        ];

        $write = static fn (string $path, string $template, ?int $mode = null) => self::writeFile(
            $directory . '/' . $path,
            $template,
            $variables,
            $output,
            $directory,
            $mode,
        );

        $write('composer.json', self::composerTemplate($type));
        $write('.gitignore', self::gitignoreTemplate());

        match ($type) {
            ProjectType::Api => self::writeApiFiles($write),
            ProjectType::Console => self::writeConsoleFiles($write),
        };
    }

    /** @param \Closure(string, string, ?int=): void $write */
    private static function writeApiFiles(\Closure $write): void
    {
        $write('public/index.php', self::apiIndexTemplate());
        $write('routes.php', self::apiRoutesTemplate());
        $write('src/Routes/Home.php', self::apiHomeTemplate());
    }

    /** @param \Closure(string, string, ?int=): void $write */
    private static function writeConsoleFiles(\Closure $write): void
    {
        $write('bin/app', self::consoleBinTemplate(), 0755);
        $write('commands.php', self::consoleCommandsTemplate());
        $write('src/Commands/Hello.php', self::consoleHelloTemplate());
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
        ?int $mode = null,
    ): void {
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        $content = TemplateRenderer::render($template, $variables);

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }

        if ($mode !== null && !@chmod($path, $mode)) {
            throw new \RuntimeException("Failed to chmod file: {$path}");
        }

        $relative = ltrim(substr($path, strlen($projectDir)), '/');
        $output->writeln("  Created {$relative}");
    }

    private static function composerTemplate(ProjectType $type): string
    {
        $framework = match ($type) {
            ProjectType::Api => '"phalanx-php/http": "^0.7"',
            ProjectType::Console => '"phalanx-php/console": "^0.7"',
        };

        $bin = match ($type) {
            ProjectType::Api => '',
            ProjectType::Console => "\n    \"bin\": [\"bin/app\"],",
        };

        return <<<TEMPLATE
{
    "name": "app/{{name}}",
    "description": "A Phalanx application",
    "type": "project",
    "license": "MIT",{$bin}
    "require": {
        "php": "^8.4",
        "ext-swoole": "^6.0",
        "phalanx-php/runtime": "^0.7",
        {$framework},
        "symfony/runtime": "^7.0"
    },
    "autoload": {
        "psr-4": {
            "{{namespace_escaped}}\\\\": "src/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
TEMPLATE;
    }

    private static function apiIndexTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload_runtime.php';

use Phalanx\Http\Http;

return static function (array $context): void {
    Http::starting($context)
        ->routes(__DIR__ . '/../routes.php')
        ->listen('127.0.0.1:8080')
        ->run();
};
TEMPLATE;
    }

    private static function apiRoutesTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

use {{namespace}}\Routes\Home;
use Phalanx\Http\RouteGroup;

return RouteGroup::of([
    'GET /' => Home::class,
]);
TEMPLATE;
    }

    private static function apiHomeTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

namespace {{namespace}}\Routes;

use Phalanx\Http\RequestContext;
use Phalanx\Task\Scopeable;

final class Home implements Scopeable
{
    public function __invoke(RequestContext $ctx): array
    {
        return [
            'message' => 'Welcome to Phalanx',
            'method' => $ctx->method(),
            'path' => $ctx->path(),
        ];
    }
}
TEMPLATE;
    }

    private static function consoleBinTemplate(): string
    {
        return <<<'TEMPLATE'
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload_runtime.php';

use Phalanx\Console\Console;
use Phalanx\Console\Style\Bundle;

return static function (array $context): int {
    return Console::starting($context)
        ->providers(new Bundle())
        ->commands(__DIR__ . '/../commands.php')
        ->run();
};
TEMPLATE;
    }

    private static function consoleCommandsTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

use {{namespace}}\Commands\Hello;
use Phalanx\Console\Command\CommandGroup;

return CommandGroup::of([
    'hello' => Hello::class,
]);
TEMPLATE;
    }

    private static function consoleHelloTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

namespace {{namespace}}\Commands;

use Phalanx\Console\Command\Arg;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class Hello implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Greet someone by name',
            arguments: [Arg::required('name', 'Person to greet')],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $name = (string) $ctx->args->required('name');

        $output = $ctx->service(StreamOutput::class);
        $output->persist("Hello, {$name}! Welcome to Phalanx.");

        return 0;
    }
}
TEMPLATE;
    }

    private static function gitignoreTemplate(): string
    {
        return <<<'TEMPLATE'
.*
*.md
!.gitignore
!.env.example
!README.md
/vendor/
.env
TEMPLATE;
    }
}

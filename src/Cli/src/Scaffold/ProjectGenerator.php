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

        $write = static fn (string $path, string $template) => self::writeFile(
            $directory . '/' . $path,
            $template,
            $variables,
            $output,
            $directory,
        );

        $write('composer.json', self::composerTemplate($type));
        $write('.gitignore', self::gitignoreTemplate());

        match ($type) {
            ProjectType::Api => self::writeApiFiles($write),
            ProjectType::Console => self::writeConsoleFiles($write),
        };
    }

    /** @param \Closure(string, string): void $write */
    private static function writeApiFiles(\Closure $write): void
    {
        $write('public/index.php', self::apiIndexTemplate());
        $write('routes.php', self::apiRoutesTemplate());
        $write('src/Routes/Home.php', self::apiHomeTemplate());
    }

    /** @param \Closure(string, string): void $write */
    private static function writeConsoleFiles(\Closure $write): void
    {
        $write('bin/app', self::consoleBinTemplate());
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
    ): void {
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        $content = TemplateRenderer::render($template, $variables);

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }

        $relative = ltrim(substr($path, strlen($projectDir)), '/');
        $output->writeln("  Created {$relative}");
    }

    private static function composerTemplate(ProjectType $type): string
    {
        $framework = match ($type) {
            ProjectType::Api => '"phalanx-php/stoa": "^0.6"',
            ProjectType::Console => '"phalanx-php/archon": "^0.6"',
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
        "ext-openswoole": "^26.0",
        "phalanx-php/aegis": "^0.6",
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

use Phalanx\Stoa\Stoa;

return static function (array $context): void {
    Stoa::starting($context)
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
use Phalanx\Stoa\RouteGroup;

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

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class Home implements Scopeable
{
    public function __invoke(RequestScope $scope): array
    {
        return [
            'message' => 'Welcome to Phalanx',
            'method' => $scope->method(),
            'path' => $scope->path(),
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

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Console\Style\ConsoleServiceBundle;

return static function (array $context): int {
    return Archon::starting($context)
        ->providers(new ConsoleServiceBundle())
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
use Phalanx\Archon\Command\CommandArgument;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;

return CommandGroup::of([
    'hello' => [
        Hello::class,
        new CommandConfig(
            description: 'Greet someone by name',
            arguments: [new CommandArgument('name', 'Person to greet')],
        ),
    ],
]);
TEMPLATE;
    }

    private static function consoleHelloTemplate(): string
    {
        return <<<'TEMPLATE'
<?php

declare(strict_types=1);

namespace {{namespace}}\Commands;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class Hello implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $name = (string) $scope->args->required('name');

        $output = $scope->service(StreamOutput::class);
        $output->persist("Hello, {$name}! Welcome to Phalanx.");

        return 0;
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

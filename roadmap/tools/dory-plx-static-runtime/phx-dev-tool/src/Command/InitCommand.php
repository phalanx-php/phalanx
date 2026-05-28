<?php

declare(strict_types=1);

namespace Phx\Command;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class InitCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $output->persist("<info>Initializing Phalanx project...</info>");

        $projectRoot = getcwd();

        // 1. Create directory structure
        $dirs = ['src', 'bin', 'tests', 'config'];
        foreach ($dirs as $dir) {
            $path = $projectRoot . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
                $output->persist("<comment>  - Created {$dir}/</comment>");
            }
        }

        // 2. Create composer.json
        $composerPath = $projectRoot . '/composer.json';
        if (!file_exists($composerPath)) {
            $composerJson = [
                'name' => 'phalanx-app/' . basename($projectRoot),
                'type' => 'project',
                'require' => [
                    'php' => '^8.4',
                    'phalanx-php/archon' => '@dev',
                    'phalanx-php/aegis' => '@dev',
                    'symfony/runtime' => '^7.0'
                ],
                'autoload' => [
                    'psr-4' => [
                        'App\\' => 'src/'
                    ]
                ],
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
                'config' => [
                    'allow-plugins' => [
                        'symfony/runtime' => true
                    ]
                ]
            ];
            file_put_contents($composerPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $output->persist("<comment>  - Created composer.json</comment>");
        }

        // 3. Create example command
        $exampleCommand = $projectRoot . '/src/ExampleCommand.php';
        if (!file_exists($exampleCommand)) {
            $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class ExampleCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $ctx->service(StreamOutput::class)->persist("Hello from your new Phalanx app!");
        return 0;
    }
}
PHP;
            file_put_contents($exampleCommand, $content);
            $output->persist("<comment>  - Created src/ExampleCommand.php</comment>");
        }

        // 4. Create bin/app.php
        $appPath = $projectRoot . '/bin/app.php';
        if (!file_exists($appPath)) {
            $content = <<<'PHP'
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\ExampleCommand;
use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;

$commands = CommandGroup::of([
    'example' => [
        ExampleCommand::class,
        new CommandConfig(description: 'An example command.'),
    ],
]);

exit(Archon::starting()
    ->commands($commands)
    ->build()
    ->run());
PHP;
            file_put_contents($appPath, $content);
            $output->persist("<comment>  - Created bin/app.php</comment>");
        }

        $output->persist("<info>Initialization complete!</info>");
        $output->persist("<comment>Run 'composer install' then 'dory serve' to start.</comment>");

        return 0;
    }
}

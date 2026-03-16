<?php

declare(strict_types=1);

namespace Convoy\Tests\Unit\Handler;

use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Handler\HandlerLoader;
use Convoy\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HandlerLoaderTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/convoy-handler-test-' . uniqid();
        mkdir($this->fixtureDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->fixtureDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->fixtureDir);
    }

    #[Test]
    public function loads_handler_group_from_file(): void
    {
        $content = <<<'PHP'
<?php
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Task\Task;

return HandlerGroup::of([
    'task-a' => Handler::of(Task::of(static fn() => 'list')),
]);
PHP;

        file_put_contents($this->fixtureDir . '/handlers.php', $content);

        $group = HandlerLoader::load($this->fixtureDir . '/handlers.php');

        $this->assertInstanceOf(HandlerGroup::class, $group);
        $this->assertContains('task-a', $group->keys());
    }

    #[Test]
    public function throws_for_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler file not found');

        HandlerLoader::load('/nonexistent/file.php');
    }

    #[Test]
    public function throws_for_invalid_return_type(): void
    {
        $content = <<<'PHP'
<?php
return 'not a handler group';
PHP;

        file_put_contents($this->fixtureDir . '/invalid.php', $content);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler file must return a group or Closure');

        HandlerLoader::load($this->fixtureDir . '/invalid.php');
    }

}

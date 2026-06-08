<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Handler;

use Phalanx\Handler\HandlerGroup;
use Phalanx\Handler\HandlerLoader;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HandlerLoaderTest extends TestCase
{
    use UsesTempWorkspace;

    private string $fixtureDir;

    #[Test]
    public function loads_handler_group_from_file(): void
    {
        $content = <<<'PHP'
<?php
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Runtime\Tests\Fixtures\Handlers\HandlerA;

return HandlerGroup::of([
	'task-a' => Handler::of(HandlerA::class),
]);
PHP;

        file_put_contents($this->fixtureDir . '/handlers.php', $content);

        $group = HandlerLoader::load(null, $this->fixtureDir . '/handlers.php');

        $this->assertInstanceOf(HandlerGroup::class, $group);
        $this->assertContains('task-a', $group->keys());
    }

    #[Test]
    public function throws_for_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler file not found');

        HandlerLoader::load(null, '/nonexistent/file.php');
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

        HandlerLoader::load(null, $this->fixtureDir . '/invalid.php');
    }

    protected function setUp(): void
    {
        $this->fixtureDir = $this->tempWorkspace('phalanx-handler-test-')->dir('handlers');
    }
}

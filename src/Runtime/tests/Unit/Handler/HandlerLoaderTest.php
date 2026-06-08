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
    #[Test]
    public function loadsHandlerGroupFromFile(): void
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

        $handlers = $this->tempWorkspace()->file('handlers/handlers.php', $content);

        $group = HandlerLoader::load(null, $handlers);

        $this->assertInstanceOf(HandlerGroup::class, $group);
        $this->assertContains('task-a', $group->keys());
    }

    #[Test]
    public function throwsForMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler file not found');

        HandlerLoader::load(null, $this->tempWorkspace('phalanx-handler-test-')->missingPath('missing.php'));
    }

    #[Test]
    public function throwsForInvalidReturnType(): void
    {
        $content = <<<'PHP'
<?php
return 'not a handler group';
PHP;

        $invalid = $this->tempWorkspace()->file('handlers/invalid.php', $content);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler file must return a group or Closure');

        HandlerLoader::load(null, $invalid);
    }
}

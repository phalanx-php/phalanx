<?php

declare(strict_types=1);

namespace BgAgents\Tests\Unit\Specialist;

use BgAgents\Config\ModelDefaults;
use BgAgents\Specialist\SpecLoadException;
use BgAgents\Specialist\SpecialistLoader;
use PHPUnit\Framework\TestCase;

final class SpecialistLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bg-agents-spec-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function defaults(): ModelDefaults
    {
        return new ModelDefaults('opus-default', 'sonnet-default', 'flash-default', 'sonnet-default');
    }

    public function test_loads_full_spec(): void
    {
        file_put_contents($this->dir . '/foo.md', <<<MD
        ---
        name: foo
        addressing: ["@foo", "@f"]
        provider: anthropic
        model: claude-sonnet-4-6
        temperature: 0.4
        description: Foo specialist
        subscription:
          kinds: [custom, log]
          tags: [foo, bar]
          severity_min: warn
        rag:
          tags: [bg.memory, foo]
          topics: [topic-a]
        ---
        You are foo. Be terse.
        MD);

        $loader = new SpecialistLoader($this->defaults());
        $specs = $loader->loadAll($this->dir);

        self::assertArrayHasKey('foo', $specs);
        $foo = $specs['foo'];
        self::assertSame('foo', $foo->name);
        self::assertSame(['@foo', '@f'], $foo->addressing);
        self::assertSame('anthropic', $foo->provider);
        self::assertSame('claude-sonnet-4-6', $foo->model);
        self::assertEqualsWithDelta(0.4, $foo->temperature, 0.0001);
        self::assertSame('You are foo. Be terse.', $foo->identityPrompt);
        self::assertSame(['custom', 'log'], $foo->subscription->kinds);
        self::assertSame(['foo', 'bar'], $foo->subscription->tags);
        self::assertSame('warn', $foo->subscription->severityMin);
        self::assertSame(['bg.memory', 'foo'], $foo->ragTags);
        self::assertSame(['topic-a'], $foo->ragTopics);
    }

    public function test_supervisor_default_uses_supervisor_model(): void
    {
        file_put_contents($this->dir . '/supervisor.md', "---\nname: supervisor\n---\nbody\n");
        $loader = new SpecialistLoader($this->defaults());
        $specs = $loader->loadAll($this->dir);

        self::assertSame('opus-default', $specs['supervisor']->model);
    }

    public function test_other_specs_default_to_specialist_model(): void
    {
        file_put_contents($this->dir . '/foo.md', "---\nname: foo\n---\nbody\n");
        $loader = new SpecialistLoader($this->defaults());
        $specs = $loader->loadAll($this->dir);

        self::assertSame('sonnet-default', $specs['foo']->model);
    }

    public function test_missing_frontmatter_throws(): void
    {
        file_put_contents($this->dir . '/bad.md', "no frontmatter at all\n");
        $loader = new SpecialistLoader($this->defaults());

        $this->expectException(SpecLoadException::class);
        $loader->loadAll($this->dir);
    }

    public function test_unterminated_frontmatter_throws(): void
    {
        file_put_contents($this->dir . '/bad.md', "---\nname: foo\nopen ended");
        $loader = new SpecialistLoader($this->defaults());

        $this->expectException(SpecLoadException::class);
        $loader->loadAll($this->dir);
    }

    public function test_invalid_yaml_throws(): void
    {
        file_put_contents($this->dir . '/bad.md', "---\nname: [\n---\nbody\n");
        $loader = new SpecialistLoader($this->defaults());

        $this->expectException(SpecLoadException::class);
        $loader->loadAll($this->dir);
    }

    public function test_filename_supplies_default_name(): void
    {
        file_put_contents($this->dir . '/auto.md', "---\n{}\n---\nbody\n");
        $loader = new SpecialistLoader($this->defaults());
        $specs = $loader->loadAll($this->dir);

        self::assertArrayHasKey('auto', $specs);
        self::assertSame('auto', $specs['auto']->name);
    }
}

<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Lego;

use AgentBridge\Lego\LegoDefinition;
use AgentBridge\Lego\LegoLibrary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LegoLibraryTest extends TestCase
{
    private string $basePath;
    private LegoLibrary $library;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/agent-bridge-lego-test-' . bin2hex(random_bytes(8));
        $this->library  = new LegoLibrary($this->basePath);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->basePath);
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) glob("{$dir}/*") as $entry) {
            is_dir((string) $entry)
                ? self::removeDirectory((string) $entry)
                : unlink((string) $entry);
        }

        rmdir($dir);
    }

    private static function makeLego(
        string $name = 'click-submit',
        string $domain = 'example.com',
    ): LegoDefinition {
        return new LegoDefinition(
            name:        $name,
            domain:      $domain,
            description: 'Test lego',
            steps:       [['op' => 'click', 'selector' => '#submit']],
        );
    }

    #[Test]
    public function save_and_load_round_trip(): void
    {
        $lego = self::makeLego();

        $this->library->save($lego);

        $loaded = $this->library->forDomain('example.com');

        self::assertCount(1, $loaded);
        self::assertSame('click-submit', $loaded[0]->name);
        self::assertSame('example.com',  $loaded[0]->domain);
        self::assertSame($lego->steps,   $loaded[0]->steps);
    }

    #[Test]
    public function count_for_domain_matches_file_count(): void
    {
        $this->library->save(self::makeLego('lego-1'));
        $this->library->save(self::makeLego('lego-2'));
        $this->library->save(self::makeLego('lego-3'));

        self::assertSame(3, $this->library->countForDomain('example.com'));
    }

    #[Test]
    public function for_domain_returns_empty_array_for_unknown_domain(): void
    {
        $result = $this->library->forDomain('nonexistent.com');

        self::assertSame([], $result);
    }

    #[Test]
    public function count_for_domain_returns_zero_for_unknown_domain(): void
    {
        self::assertSame(0, $this->library->countForDomain('nonexistent.com'));
    }

    #[Test]
    public function delete_removes_file_and_for_domain_returns_empty(): void
    {
        $this->library->save(self::makeLego());

        self::assertCount(1, $this->library->forDomain('example.com'));

        $this->library->delete('example.com', 'click-submit');

        self::assertSame([], $this->library->forDomain('example.com'));
    }

    #[Test]
    public function delete_is_idempotent_for_nonexistent_file(): void
    {
        // Must not throw even if the file was never created.
        $this->library->delete('example.com', 'never-existed');

        self::assertTrue(true);
    }

    #[Test]
    public function get_returns_correct_lego_by_name(): void
    {
        $this->library->save(self::makeLego('lego-a'));
        $this->library->save(self::makeLego('lego-b'));

        $found = $this->library->get('example.com', 'lego-a');

        self::assertNotNull($found);
        self::assertSame('lego-a', $found->name);
    }

    #[Test]
    public function get_returns_null_for_nonexistent_lego(): void
    {
        self::assertNull($this->library->get('example.com', 'does-not-exist'));
    }

    #[Test]
    public function legos_from_different_domains_are_isolated(): void
    {
        $this->library->save(self::makeLego('shared-name', 'domain-a.com'));
        $this->library->save(self::makeLego('shared-name', 'domain-b.com'));

        $a = $this->library->forDomain('domain-a.com');
        $b = $this->library->forDomain('domain-b.com');

        self::assertCount(1, $a);
        self::assertSame('domain-a.com', $a[0]->domain);

        self::assertCount(1, $b);
        self::assertSame('domain-b.com', $b[0]->domain);
    }

    #[Test]
    public function save_overwrites_existing_lego_with_same_name(): void
    {
        $original = self::makeLego();
        $this->library->save($original);

        $updated = $original->withExecution(succeeded: true);
        $this->library->save($updated);

        $loaded = $this->library->forDomain('example.com');

        self::assertCount(1, $loaded);
        self::assertSame(1, $loaded[0]->executions);
    }
}

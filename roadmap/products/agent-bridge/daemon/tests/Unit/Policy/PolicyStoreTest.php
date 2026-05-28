<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Policy;

use AgentBridge\Policy\DomainPolicy;
use AgentBridge\Policy\PolicyRule;
use AgentBridge\Policy\PolicyStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyStoreTest extends TestCase
{
    private string $basePath;
    private PolicyStore $store;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/agent-bridge-policy-test-' . bin2hex(random_bytes(8));
        $this->store = new PolicyStore($this->basePath);
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

    #[Test]
    public function for_domain_returns_empty_policy_for_unknown_domain(): void
    {
        $policy = $this->store->forDomain('example.com');

        self::assertSame('example.com', $policy->domain);
        self::assertSame([], $policy->rules);
        self::assertSame(0, $policy->totalActions);
        self::assertSame(0, $policy->totalOverrides);
        self::assertSame([], $policy->userActionLog);
    }

    #[Test]
    public function save_and_load_round_trip(): void
    {
        $policy = DomainPolicy::empty('example.com')
            ->withUserAction(['action' => 'click', 'target' => 'button.archive'])
            ->withRules([
                new PolicyRule('archive-email', ['selector' => 'button.archive'], 0.85, 10, 1),
            ]);

        $this->store->save($policy);

        $loaded = $this->store->forDomain('example.com');

        self::assertSame('example.com', $loaded->domain);
        self::assertSame(1, $loaded->totalActions);
        self::assertCount(1, $loaded->rules);
        self::assertSame('archive-email', $loaded->rules[0]->legoName);
        self::assertSame(0.85, $loaded->rules[0]->confidence);
        self::assertCount(1, $loaded->userActionLog);
    }

    #[Test]
    public function record_user_action_appends_to_action_log(): void
    {
        $this->store->recordUserAction('example.com', ['action' => 'click', 'target' => '.btn']);
        $this->store->recordUserAction('example.com', ['action' => 'type', 'target' => '#search', 'value' => 'hello']);

        $policy = $this->store->forDomain('example.com');

        self::assertSame(2, $policy->totalActions);
        self::assertCount(2, $policy->userActionLog);
        self::assertSame('click', $policy->userActionLog[0]['action']);
        self::assertSame('type', $policy->userActionLog[1]['action']);
    }

    #[Test]
    public function record_user_action_creates_policy_file_on_first_call(): void
    {
        self::assertFalse(is_dir($this->basePath));

        $this->store->recordUserAction('example.com', ['action' => 'click']);

        self::assertTrue(is_dir($this->basePath));
        self::assertFileExists($this->basePath . '/example.com.json');
    }

    #[Test]
    public function policies_for_different_domains_are_isolated(): void
    {
        $this->store->recordUserAction('domain-a.com', ['action' => 'click']);
        $this->store->recordUserAction('domain-a.com', ['action' => 'scroll']);
        $this->store->recordUserAction('domain-b.com', ['action' => 'fill']);

        $a = $this->store->forDomain('domain-a.com');
        $b = $this->store->forDomain('domain-b.com');

        self::assertSame(2, $a->totalActions);
        self::assertSame(1, $b->totalActions);
    }

    #[Test]
    public function save_overwrites_existing_policy(): void
    {
        $this->store->recordUserAction('example.com', ['action' => 'click']);

        $policy = $this->store->forDomain('example.com');
        self::assertSame(1, $policy->totalActions);

        // Overwrite with a new policy that has 0 actions
        $this->store->save(DomainPolicy::empty('example.com'));

        $reloaded = $this->store->forDomain('example.com');
        self::assertSame(0, $reloaded->totalActions);
    }

    #[Test]
    public function domain_with_path_chars_is_sanitized(): void
    {
        // Domains with special chars should not escape the base path.
        // This verifies the sanitization doesn't throw and produces a usable key.
        $this->store->recordUserAction('example.com/path', ['action' => 'click']);

        $policy = $this->store->forDomain('example.com/path');
        self::assertSame(1, $policy->totalActions);
    }

    #[Test]
    public function policy_rule_round_trips_correctly(): void
    {
        $rule = new PolicyRule(
            legoName: 'test-lego',
            match: ['selector' => '.btn', 'text' => 'Submit'],
            confidence: 0.9,
            applied: 5,
            overridden: 1,
        );

        $policy = DomainPolicy::empty('example.com')->withRules([$rule]);
        $this->store->save($policy);

        $loaded = $this->store->forDomain('example.com');

        self::assertCount(1, $loaded->rules);
        $r = $loaded->rules[0];
        self::assertSame('test-lego', $r->legoName);
        self::assertSame(['selector' => '.btn', 'text' => 'Submit'], $r->match);
        self::assertSame(0.9, $r->confidence);
        self::assertSame(5, $r->applied);
        self::assertSame(1, $r->overridden);
    }
}

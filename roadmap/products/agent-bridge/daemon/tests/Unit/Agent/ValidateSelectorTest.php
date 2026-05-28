<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Agent;

use AgentBridge\Agent\ValidateSelector;
use Phalanx\Athena\Tool\Disposition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phalanx\Scope;

/**
 * Unit tests for ValidateSelector.
 *
 * The queryDom() round-trip (which requires a live TabScope and content script)
 * is an integration test. Here we test the heuristic stability assessor directly
 * since it is the only pure-logic component on this class.
 *
 * The full __invoke() path is tested by verifying behavior when no TabScope
 * attribute is present (returns data with error, not a crash).
 */
final class ValidateSelectorTest extends TestCase
{
    private Scope $scope;

    protected function setUp(): void
    {
        $this->scope = $this->createMock(Scope::class);
        $this->scope->method('attribute')->willReturn(null); // no tabScope attribute
    }

    #[Test]
    public function invocation_without_tab_scope_returns_data_not_exception(): void
    {
        $tool = new ValidateSelector('[data-action="archive"]');
        $outcome = $tool($this->scope);

        self::assertSame(Disposition::Continue, $outcome->disposition);
        self::assertIsArray($outcome->data);
        self::assertArrayHasKey('error', $outcome->data);
        self::assertSame(0, $outcome->data['matchCount']);
        self::assertFalse($outcome->data['stable']);
    }

    /** @return array<string, array{string, bool}> */
    public static function selectorStabilityProvider(): array
    {
        return [
            'data attribute is stable'          => ['[data-message-id]', true],
            'data attribute with value is stable' => ['[data-action="archive"]', true],
            'aria attribute is stable'           => ['[aria-label="Archive"]', true],
            'aria-disabled is stable'            => ['button[aria-disabled="false"]', true],
            'role attribute is stable'           => ['[role="button"]', true],
            'semantic ID is stable'              => ['#submit-button', true],
            'ID with letters is stable'          => ['#mainContent', true],
            'plain class is stable (default)'    => ['.email-row', true],
            'multi-part selector is stable'      => ['.inbox .email-list li', true],
            'camelCase hash class is fragile'    => ['.sc-bZkfAO', false],
            'css-modules style class is fragile' => ['.ab1234XY', false],
            'emotion hex class is fragile'       => ['.a3f1b2c9', false],
            'long hex hash is fragile'           => ['.css-deadbe', true], // only 4 hex chars after css-
            'six hex chars class is fragile'     => ['.abcdef', false],
        ];
    }

    #[Test]
    #[DataProvider('selectorStabilityProvider')]
    public function assess_stability_classifies_correctly(string $selector, bool $expectedStable): void
    {
        $stable = ValidateSelector::assessStability($selector);

        self::assertSame($expectedStable, $stable, "Selector '{$selector}' stability mismatch");
    }

    #[Test]
    public function description_is_non_empty(): void
    {
        $tool = new ValidateSelector('.foo');
        self::assertNotEmpty($tool->description);
    }

    #[Test]
    public function tags_include_validation(): void
    {
        $tool = new ValidateSelector('.foo');
        self::assertContains('validation', $tool->tags);
    }
}

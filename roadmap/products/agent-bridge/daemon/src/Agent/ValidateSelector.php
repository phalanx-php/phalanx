<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use AgentBridge\Tab\TabScope;
use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope;

/**
 * Non-terminal tool for the GeneratorAgent.
 *
 * The AI may call this multiple times before calling CreateLegos. Each call
 * sends a dom.request to the content script and awaits the response.
 * ToolOutcome::data() (Disposition::Continue) keeps the agent loop alive.
 *
 * Scope attribute contract: the GeneratorAgent's execution scope MUST carry
 * a 'tabScope' attribute of type TabScope. Without it this tool returns an
 * error result and the AI is informed -- it cannot validate selectors but
 * may still call CreateLegos with its best guess.
 */
final class ValidateSelector implements Tool
{
    public string $description {
        get => 'Validate a CSS selector against the live DOM. Returns how many elements match and whether the selector is likely stable (uses data attributes, aria labels, or role attributes rather than generated class names).';
    }

    public array $tags {
        get => ['validation', 'generation'];
    }

    public function __construct(
        #[Param('CSS selector to validate against the live DOM')]
        public readonly string $selector,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $tabScope = $scope->attribute('tabScope');

        if (!$tabScope instanceof TabScope) {
            return ToolOutcome::data([
                'matchCount' => 0,
                'stable' => false,
                'error' => 'No tab context available for DOM validation',
            ]);
        }

        try {
            $elements = $tabScope->queryDom($this->selector, limit: 100);

            return ToolOutcome::data([
                'matchCount' => count($elements),
                'stable' => self::assessStability($this->selector),
                'sampleAttributes' => array_slice($elements, 0, 3),
            ]);
        } catch (\Throwable $e) {
            return ToolOutcome::data([
                'matchCount' => 0,
                'stable' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Heuristic stability check.
     *
     * Data attributes, aria attributes, role attributes, and plain ID selectors
     * are considered stable -- they are semantic and survive site deploys.
     * Class names matching auto-generated patterns (CSS Modules, Tailwind JIT
     * hashes, emotion-style hex strings) are considered fragile.
     */
    public static function assessStability(string $selector): bool
    {
        $stablePatterns = [
            '/\[data-/',
            '/\[aria-/',
            '/\[role=/',
            '/#[a-zA-Z]/',
        ];

        foreach ($stablePatterns as $pattern) {
            if (preg_match($pattern, $selector)) {
                return true;
            }
        }

        // Auto-generated class names: styled-components (sc-*), CSS Modules, emotion
        if (preg_match('/\.sc-[a-zA-Z]{4,}/', $selector)
            || preg_match('/\.[a-z]{1,3}[A-Z][a-zA-Z0-9]{4,}/', $selector)) {
            return false;
        }

        // Hex-encoded class names (emotion hash, Tailwind JIT extended)
        if (preg_match('/\.[a-f0-9]{6,}/', $selector)) {
            return false;
        }

        return true;
    }
}

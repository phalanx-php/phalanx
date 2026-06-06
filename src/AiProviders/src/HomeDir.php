<?php

declare(strict_types=1);

namespace Phalanx\AiProviders;

use Phalanx\AiProviders\Conversation\Parser;
use Phalanx\AiProviders\HomeDir\Locators;
use Phalanx\AiProviders\HomeDir\Projects;
use Phalanx\AiProviders\HomeDir\Settings;

/**
 * Read-only view of an AI tool's home directory. Each tool (Claude Code,
 * Codex, Gemini CLI, etc.) has a concrete implementation that knows how to
 * locate projects, parse conversation logs, and surface tool-specific
 * settings. Consumers receive a typed view rather than raw filesystem
 * paths.
 */
interface HomeDir
{
    public function projects(): Projects;

    public function locators(): Locators;

    public function parser(): Parser;

    public function settings(): Settings;
}

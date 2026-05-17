<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Conversation\Parser;
use Phalanx\Panoply\HomeDir\Locators;
use Phalanx\Panoply\HomeDir\Projects;
use Phalanx\Panoply\HomeDir\Settings;

/**
 * Read-only view of an AI tool's home directory. Each tool (Claude Code,
 * Codex, Gemini CLI, etc.) has a concrete implementation that knows how to
 * locate projects, parse conversation logs, and surface tool-specific
 * settings. Consumers receive a typed facade rather than raw filesystem
 * paths.
 */
interface HomeDir
{
    public function projects(): Projects;

    public function locators(): Locators;

    public function parser(): Parser;

    public function settings(): Settings;
}

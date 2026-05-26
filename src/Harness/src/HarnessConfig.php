<?php

declare(strict_types=1);

namespace Phalanx\Harness;

use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;

final class HarnessConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public HarnessMode $mode {
        get => $this->durable ? HarnessMode::Durable : HarnessMode::Ephemeral;
    }

    public function __construct(
        #[Env(key: 'HARNESS_DURABLE', description: 'Enable Surreal-backed durable mode for session persistence and replay')]
        private(set) bool $durable = false,
        #[Env(key: 'HARNESS_SESSION_ID', description: 'Session ID to resume (durable mode only)')]
        private(set) ?string $sessionId = null,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        $issues = [];

        if (!$this->durable && $this->sessionId !== null) {
            $issues[] = new Issue(
                IssueLevel::Warning,
                'harness.session-id-without-durable',
                'HARNESS_SESSION_ID is set but durable mode is not enabled. The session ID will be ignored.',
                envKey: 'HARNESS_SESSION_ID',
                path: 'sessionId',
            );
        }

        return $issues;
    }
}

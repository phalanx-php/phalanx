<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build;

use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;

final class BuildConfig implements Config
{
    private const array VALID_PROFILES = ['mini', 'ops', 'brain', 'full', 'custom'];

    public bool $configured {
        get => $this->buildRoot !== '';
    }

    public function __construct(
        #[Env(key: 'DORY_BUILD_ROOT', description: 'Root directory for build workspace')]
        private(set) string $buildRoot = '/tmp/dory-build',

        #[Env(key: 'DORY_SPC_PATH', description: 'Path to static-php-cli binary (auto-detect if empty)')]
        private(set) string $spcPath = '',

        #[Env(key: 'DORY_BUILD_PROFILE', description: 'Default build profile to use')]
        private(set) string $defaultProfile = 'full',

        #[Env(key: 'DORY_BUILD_VERBOSE', description: 'Enable verbose build output')]
        private(set) bool $verbose = false,

        #[Env(key: 'DORY_BUILD_CACHE', description: 'Enable build artifact caching')]
        private(set) bool $cacheEnabled = true,

        #[Env(key: 'DORY_INI_PATH', description: 'Path to the primary php.ini for built binaries')]
        private(set) string $iniPath = '~/.config/dory',

        #[Env(key: 'DORY_INI_SCAN_DIR', description: 'Directory scanned for additional INI files')]
        private(set) string $iniScanDir = '~/.config/dory/conf.d',
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        $issues = [];

        if ($this->buildRoot === '') {
            $issues[] = new Issue(
                IssueLevel::Error,
                'dory.build.build-root',
                'DORY_BUILD_ROOT must not be empty.',
                envKey: 'DORY_BUILD_ROOT',
                path: 'buildRoot',
            );
        }

        if (!in_array($this->defaultProfile, self::VALID_PROFILES, strict: true)) {
            $valid = implode(', ', self::VALID_PROFILES);
            $issues[] = new Issue(
                IssueLevel::Error,
                'dory.build.default-profile',
                "DORY_BUILD_PROFILE must be one of: {$valid}.",
                envKey: 'DORY_BUILD_PROFILE',
                path: 'defaultProfile',
            );
        }

        if ($this->iniPath === '') {
            $issues[] = new Issue(
                IssueLevel::Error,
                'dory.build.ini-path',
                'DORY_INI_PATH must not be empty.',
                envKey: 'DORY_INI_PATH',
                path: 'iniPath',
            );
        }

        return $issues;
    }
}

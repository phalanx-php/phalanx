<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use Phalanx\Testing\TempWorkspace;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class ReleaseReadinessCheckTest extends TestCase
{
    use UsesTempWorkspace;

    #[Test]
    public function release_readiness_passes_for_complete_fixture(): void
    {
        $workspace = $this->fixture();

        [$exitCode, $output] = self::runCheck($workspace);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Release readiness checks passed.', $output);
    }

    #[Test]
    public function release_readiness_rejects_push_triggers(): void
    {
        $workspace = $this->fixture();
        $workspace->file(
            '.github/workflows/split_modules.yaml',
            str_replace(
                "workflow_dispatch:\n",
                "push:\n    tags:\n      - 'v*'\n  workflow_dispatch:\n",
                self::workflow(),
            ),
        );

        [$exitCode, $output] = self::runCheck($workspace);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Split workflow must only run from workflow_dispatch.', $output);
    }

    #[Test]
    public function release_readiness_rejects_stale_split_matrix(): void
    {
        $workspace = $this->fixture();
        $workspace->file(
            '.github/workflows/split_modules.yaml',
            str_replace(
                "          - { local_path: 'src/Console', split_repository: 'phalanx-console' }\n",
                '',
                self::workflow(),
            ),
        );

        [$exitCode, $output] = self::runCheck($workspace);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Split workflow matrix must exactly match module paths and repository names.', $output);
    }

    #[Test]
    public function release_readiness_rejects_dev_constraints_in_publish_metadata(): void
    {
        $workspace = $this->fixture();
        $this->module($workspace, 'Console', 'phalanx-php/console', [
            'phalanx-php/runtime' => '0.7.x-dev',
        ]);

        [$exitCode, $output] = self::runCheck($workspace);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Console require.phalanx-php/runtime must use ^0.7 for publish metadata.', $output);
    }

    #[Test]
    public function release_readiness_requires_confirmation_before_mutation_steps(): void
    {
        $workspace = $this->fixture();
        $workflow = self::workflow();
        $confirmation = <<<'YAML'
      - name: Require explicit split confirmation
        run: |
          if [ "${{ github.event.inputs.confirmation }}" != "SPLIT PHALANX PACKAGES" ]; then
            exit 1
          fi

YAML;
        $mutation = <<<'YAML'
      - name: Ensure split repositories exist
        if: github.event.inputs.action == 'split'
        run: gh repo view phalanx-php/example

YAML;
        $workflow = str_replace($confirmation, '', $workflow);
        $workflow = str_replace($mutation, $mutation . $confirmation, $workflow);
        $workspace->file('.github/workflows/split_modules.yaml', $workflow);

        [$exitCode, $output] = self::runCheck($workspace);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Split confirmation step must run before all mutation steps.', $output);
    }

    private function fixture(): TempWorkspace
    {
        $workspace = $this->tempWorkspace('phalanx-release-check-');
        $workspace->file('composer.json', self::json([
            'name' => 'phalanx-php/phalanx',
            'replace' => [
                'phalanx-php/console' => 'self.version',
                'phalanx-php/runtime' => 'self.version',
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'src/*',
                ],
            ],
        ]));

        $this->module($workspace, 'Runtime', 'phalanx-php/runtime');
        $this->module($workspace, 'Console', 'phalanx-php/console', [
            'phalanx-php/runtime' => '^0.7',
        ]);
        $workspace->file('.github/workflows/split_modules.yaml', self::workflow());

        return $workspace;
    }

    /** @param array<string, string> $requires */
    private function module(
        TempWorkspace $workspace,
        string $module,
        string $package,
        array $requires = [],
    ): void {
        $repository = 'phalanx-' . substr($package, strlen('phalanx-php/'));
        $url = "https://github.com/phalanx-php/{$repository}";

        $workspace->file("src/{$module}/composer.json", self::json([
            'name' => $package,
            'homepage' => $url,
            'support' => [
                'source' => $url,
            ],
            'require' => $requires,
            'extra' => [
                'branch-alias' => [
                    'dev-main' => '0.7.x-dev',
                ],
            ],
        ]));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function runCheck(TempWorkspace $workspace): array
    {
        $lines = [];
        exec(
            escapeshellarg(PHP_BINARY)
                . ' '
                . escapeshellarg(dirname(__DIR__, 2) . '/tools/release-readiness-check.php')
                . ' --root '
                . escapeshellarg($workspace->root)
                . ' 2>&1',
            $lines,
            $exitCode,
        );

        return [$exitCode, implode("\n", $lines)];
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    private static function workflow(): string
    {
        return <<<'YAML'
name: Split Modules

on:
  workflow_dispatch:
    inputs:
      action:
        required: true
        default: "dry-run"
        type: choice
        options:
          - "dry-run"
          - "split"
      confirmation:
        required: false
        type: string

jobs:
  readiness:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Validate release readiness
        run: composer release:check

  split:
    needs: readiness
    if: github.event.inputs.action == 'split'
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        package:
          - { local_path: 'src/Runtime', split_repository: 'phalanx-runtime' }
          - { local_path: 'src/Console', split_repository: 'phalanx-console' }

    steps:
      - uses: actions/checkout@v4

      - name: Require explicit split confirmation
        run: |
          if [ "${{ github.event.inputs.confirmation }}" != "SPLIT PHALANX PACKAGES" ]; then
            exit 1
          fi

      - name: Ensure split repositories exist
        if: github.event.inputs.action == 'split'
        run: gh repo view phalanx-php/example

      - name: Split module
        if: github.event.inputs.action == 'split'
        run: |
          REPO_NAME=$(basename ${{ matrix.package.split_repository }})
          SPLIT_BRANCH="split-${REPO_NAME}-${GITHUB_RUN_ID}"
          git subtree split --prefix="${{ matrix.package.local_path }}" -b "$SPLIT_BRANCH"
          git push "https://x-access-token:${GH_TOKEN}@github.com/phalanx-php/${REPO_NAME}.git" "$SPLIT_BRANCH:main" --force
          git branch -D "$SPLIT_BRANCH"
YAML;
    }
}

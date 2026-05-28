<?php

declare(strict_types=1);

namespace ThreePath\Command;

use Phalanx\Archon\CommandContext;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Style\Theme;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use ThreePath\StbConfig;
use ThreePath\StbResponse;
use ThreePath\Task\ScanForStbs;

final class FirmwareWatchCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        /** @var CommandContext $scope */
        /** @var StbConfig $config */
        $config   = $scope->service(StbConfig::class);
        $subnet   = $scope->options->get('subnet') ?? $config->defaultSubnet;
        $interval = (int) ($scope->options->get('interval') ?? 300);
        $phpunit  = dirname(__DIR__, 3) . '/vendor/bin/phpunit';

        $theme  = Theme::default();
        $output = new StreamOutput();

        $output->persist(
            $theme->label->apply('firmware:watch')
            . "  subnet={$subnet}  interval={$interval}s"
        );

        $output->persist('Scanning for baseline...');
        $baseline = self::scanFirmware($scope, $subnet);

        if ($baseline === []) {
            $output->persist($theme->error->apply('No STBs found. Check subnet or VPN.'));
            return 1;
        }

        $versionCounts = array_count_values(array_filter(array_values($baseline)));
        arsort($versionCounts);
        foreach ($versionCounts as $fw => $count) {
            $output->persist("  {$count} device(s) on " . $theme->accent->apply((string) $fw));
        }

        $output->persist($theme->muted->apply('Watching for changes. Ctrl+C to stop.'));

        while (!$scope->isCancelled) {
            $scope->delay((float) $interval);

            $current = self::scanFirmware($scope, $subnet);
            $changes = self::detectChanges($baseline, $current);

            if ($changes === []) {
                $output->persist($theme->muted->apply('[' . date('H:i') . '] No changes'));
                continue;
            }

            $output->persist('');
            $output->persist($theme->warning->apply('[' . date('H:i:s') . '] Firmware change detected'));

            foreach ($changes as $ip => [$old, $new]) {
                $output->persist(
                    "  {$ip}  "
                    . $theme->muted->apply($old ?? 'unknown')
                    . '  →  '
                    . $theme->accent->apply($new ?? 'unknown'),
                );
            }

            $output->persist('');
            $output->persist('Running integration tests...');

            [$exitCode, $lines] = self::runTests($changes, $phpunit);

            foreach ($lines as $line) {
                $output->persist($line);
            }

            $output->persist('');

            if ($exitCode === 0) {
                $output->persist($theme->success->apply('All tests passed.'));
            } else {
                $output->persist($theme->error->apply("Tests FAILED (exit {$exitCode})"));
            }

            $output->persist('');
            $baseline = $current;
        }

        return 0;
    }

    /**
     * @return array<string, string|null>  IP => apk_version
     */
    private static function scanFirmware(ExecutionScope $scope, string $subnet): array
    {
        /** @var list<StbResponse> $stbs */
        $stbs = $scope->execute(new ScanForStbs($subnet));
        $map  = [];
        foreach ($stbs as $stb) {
            $map[$stb->ip] = $stb->get('apk_version');
        }
        ksort($map);
        return $map;
    }

    /**
     * @param  array<string, string|null> $baseline
     * @param  array<string, string|null> $current
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    private static function detectChanges(array $baseline, array $current): array
    {
        $changes = [];
        foreach ($current as $ip => $fw) {
            if (($baseline[$ip] ?? null) !== $fw) {
                $changes[$ip] = [$baseline[$ip] ?? null, $fw];
            }
        }
        return $changes;
    }

    /**
     * Picks the first changed IP for STB_DEFAULT_DEVICE_IP so tests run
     * against a device that actually received the new firmware.
     *
     * @param  array<string, array{0: string|null, 1: string|null}> $changes
     * @return array{0: int, 1: list<string>}
     */
    private static function runTests(array $changes, string $phpunit): array
    {
        $ip  = array_key_first($changes);
        $env = 'STB_DEFAULT_DEVICE_IP=' . escapeshellarg((string) $ip);
        $cmd = "{$env} php {$phpunit} --group stb --colors=never 2>&1";

        $lines  = [];
        $handle = popen($cmd, 'r');

        if ($handle === false) {
            return [1, ['Failed to launch phpunit']];
        }

        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line !== false && rtrim($line) !== '') {
                $lines[] = rtrim($line);
            }
        }

        return [pclose($handle), $lines];
    }
}

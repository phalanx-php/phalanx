<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Dory\DoryConfig;
use Phalanx\Task\Scopeable;
use Phalanx\Themis\ValidationContext;

final class DoctorCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Check environment readiness',
        );
    }
    private const string PASS = '[pass]';
    private const string FAIL = '[fail]';

    private const array OPTIONAL_EXTENSIONS = ['curl', 'mbstring', 'openssl', 'pcntl', 'posix', 'sockets'];

    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);

        $phpOk = self::checkPhpVersion($output);
        $swooleOk = self::checkSwoole($output);
        self::checkOptionalExtensions($output);
        self::checkDoryConfig($ctx, $output);

        return ($phpOk && $swooleOk) ? 0 : 1;
    }

    private static function checkPhpVersion(StreamOutput $output): bool
    {
        $version = PHP_VERSION;
        $ok = version_compare($version, '8.4.0', '>=');
        $marker = $ok ? self::PASS : self::FAIL;
        $output->persist("  {$marker} PHP >= 8.4 ({$version})");

        return $ok;
    }

    private static function checkSwoole(StreamOutput $output): bool
    {
        $ok = extension_loaded('swoole');
        $marker = $ok ? self::PASS : self::FAIL;
        $output->persist("  {$marker} Swoole extension loaded");

        return $ok;
    }

    private static function checkOptionalExtensions(StreamOutput $output): void
    {
        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            $ok = extension_loaded($ext);
            $marker = $ok ? self::PASS : self::FAIL;
            $output->persist("  {$marker} {$ext} extension");
        }
    }

    private static function checkDoryConfig(CommandContext $ctx, StreamOutput $output): void
    {
        $config = $ctx->service(DoryConfig::class);
        $issues = $config->validate(new ValidationContext());

        if ($issues === []) {
            $output->persist('  ' . self::PASS . ' Dory config valid');
            return;
        }

        $output->persist('  ' . self::FAIL . ' Dory config invalid:');

        foreach ($issues as $issue) {
            $output->persist("    [{$issue->code}] {$issue->message}");
        }
    }
}

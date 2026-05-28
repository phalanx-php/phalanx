<?php

declare(strict_types=1);

namespace Phalanx\Poc\ProcessClaims;

require __DIR__ . '/vendor/autoload.php';

final class Clock
{
    public static float $t0 = 0.0;

    public static function start(): void
    {
        self::$t0 = microtime(true);
    }

    public static function ms(): float
    {
        if (self::$t0 === 0.0) {
            self::$t0 = microtime(true);
        }
        return (microtime(true) - self::$t0) * 1000.0;
    }

    public static function stamp(string $msg): string
    {
        return sprintf('[t+%7.1fms] %s', self::ms(), $msg);
    }
}

final class Logger
{
    public static ?string $file = null;

    public static function open(string $resultPath): void
    {
        self::$file = $resultPath;
        @mkdir(dirname($resultPath), 0o755, true);
        file_put_contents($resultPath, '');
    }

    public static function line(string $line): void
    {
        $stamped = Clock::stamp($line) . PHP_EOL;
        echo $stamped;
        if (self::$file !== null) {
            file_put_contents(self::$file, $stamped, FILE_APPEND);
        }
    }

    public static function header(string $title): void
    {
        $bar = str_repeat('=', 70);
        $body = "\n{$bar}\n  {$title}\n{$bar}\n";
        echo $body;
        if (self::$file !== null) {
            file_put_contents(self::$file, $body, FILE_APPEND);
        }
    }
}

final class Sibling
{
    public int $ticks = 0;

    /** @var list<float> */
    public array $tickAtMs = [];

    public function record(): void
    {
        $this->ticks++;
        $this->tickAtMs[] = Clock::ms();
    }

    public function maxGapMs(): float
    {
        if (count($this->tickAtMs) < 2) {
            return 0.0;
        }
        $max = 0.0;
        for ($i = 1, $n = count($this->tickAtMs); $i < $n; $i++) {
            $gap = $this->tickAtMs[$i] - $this->tickAtMs[$i - 1];
            if ($gap > $max) {
                $max = $gap;
            }
        }
        return $max;
    }
}

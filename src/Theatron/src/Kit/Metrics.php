<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Kit;

final class Metrics
{
    public static function memory(int $bytes): string
    {
        if ($bytes < 1024) {
            return number_format($bytes) . ' B';
        }

        return number_format($bytes / 1024, 1) . ' KB';
    }

    public static function memoryDelta(int $bytes): string
    {
        $sign = $bytes >= 0 ? '+' : '';

        return $sign . self::memory(abs($bytes));
    }

    public static function fps(int $frames, float $elapsedSeconds): float
    {
        if ($elapsedSeconds <= 0.0) {
            return 0.0;
        }

        return $frames / $elapsedSeconds;
    }

    public static function uptime(float $seconds): string
    {
        if ($seconds < 60.0) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = (int) ($seconds / 60);
        $remaining = $seconds - ($minutes * 60);

        return sprintf('%dm%04.1fs', $minutes, $remaining);
    }
}

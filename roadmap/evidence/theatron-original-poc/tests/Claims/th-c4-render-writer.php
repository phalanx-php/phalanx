#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Theatron\Buffer\Cell;
use Phalanx\Theatron\Stage\ScreenMode;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Style\ColorMode;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Writer\AnsiWriter;

/**
 * @return Generator<int, array{int, int, Cell}>
 */
function diffFor(string $text): Generator
{
    $style = Style::new();
    $chars = str_split($text);

    foreach ($chars as $x => $char) {
        $cell = new Cell();
        $cell->set($char, $style);

        yield [$x, 0, $cell];
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class StallingStream
{
    public static string $written = '';
    public static int $zeroWrites = 0;
    public static int $maxChunk = 8192;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$zeroWrites > 0) {
            self::$zeroWrites--;

            return 0;
        }

        $chunk = substr($data, 0, self::$maxChunk);
        self::$written .= $chunk;

        return strlen($chunk);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return true;
    }
}

if (!in_array('theatron-stall', stream_get_wrappers(), true)) {
    stream_wrapper_register('theatron-stall', StallingStream::class);
}

StallingStream::$written = '';
StallingStream::$zeroWrites = 3;
StallingStream::$maxChunk = 2;

$stream = fopen('theatron-stall://writer', 'w');
assertTrue(is_resource($stream), 'stalling stream opened');

$writer = new AnsiWriter(
    colorMode: ColorMode::Ansi24,
    syncOutput: false,
    stream: $stream,
);

$writer->flushDiff(diffFor('STATUSBAR-ACTUAL'));

assertTrue(str_contains(StallingStream::$written, 'STATUSBAR-ACTUAL'), 'writer retries zero-byte and partial writes');
assertTrue(StallingStream::$zeroWrites === 0, 'zero-byte write retries were consumed');

StallingStream::$written = '';
StallingStream::$zeroWrites = 1_001;
StallingStream::$maxChunk = 8192;

$failed = false;

try {
    $writer->flushDiff(diffFor('X'));
} catch (RuntimeException) {
    $failed = true;
}

assertTrue($failed, 'writer fails loudly after bounded zero-byte stall');

putenv('COLUMNS=81');
putenv('LINES=25');

$stage = Stage::boot(new StageConfig(
    screenMode: ScreenMode::Inline,
    bracketedPaste: false,
    syncOutput: false,
    handleInput: false,
));

$fullRedraw = new ReflectionProperty($stage, 'fullRedraw');
$fullRedraw->setValue($stage, false);

$handleResize = new ReflectionMethod($stage, 'handleResize');
$handleResize->invoke($stage);

assertTrue($fullRedraw->getValue($stage) === true, 'same-size resize requests redraw');

fwrite(STDOUT, "TH-C4 render/writer claim passed\n");

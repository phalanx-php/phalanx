<?php

$pharFile = __DIR__ . '/app.phar';
if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile);
$phar->startBuffering();

$root = __DIR__ . '/bundle';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if ($file->isDir()) continue;

    $path = $file->getPathname();
    $relative = substr($path, strlen($root) + 1);

    $phar->addFile($path, $relative);
}

// Set stub - note that in micro SAPI, the stub is executed
// We use the default stub for the entry point bin/app.php
$phar->setStub("#!/usr/bin/env php\n" . $phar->createDefaultStub('bin/app.php'));

$phar->stopBuffering();

echo "PHAR created: $pharFile (" . filesize($pharFile) . " bytes)\n";

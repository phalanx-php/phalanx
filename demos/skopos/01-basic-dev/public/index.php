<?php

declare(strict_types=1);

use Phalanx\Skopos\LiveReload\ClientScript;

require __DIR__ . '/../../../../vendor/autoload.php';

echo '<!doctype html>';
echo '<html><head><title>Skopos basic dev</title>';
echo ClientScript::scriptTag(35729);
echo '</head><body>';
echo '<h1>Skopos basic dev</h1>';
echo '<p>Edit demos/skopos/01-basic-dev/public/index.php and the page reloads.</p>';
echo '<p>Time: ' . date('c') . '</p>';
echo '</body></html>';

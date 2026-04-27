<?php

declare(strict_types=1);

namespace Phalanx\Skopos\LiveReload;

final class ClientScript
{
    public static function js(int $port = 35729): string
    {
        return <<<JS
        (function(){var e=new EventSource("http://localhost:{$port}/sse");e.onmessage=function(){location.reload()};e.onerror=function(){e.close();setTimeout(function(){location.reload()},2000)};})();
        JS;
    }

    public static function scriptTag(int $port = 35729): string
    {
        $js = self::js($port);

        return "<script>{$js}</script>";
    }
}

<?php

declare(strict_types=1);

use Convoy\Scope;
use React\Http\Message\Response;

final class DashboardPage
{
    public function __invoke(Scope $scope): Response
    {
        $html = file_get_contents(__DIR__ . '/../dashboard.html');

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}

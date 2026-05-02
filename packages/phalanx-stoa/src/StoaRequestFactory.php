<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use OpenSwoole\Http\Request;
use Psr\Http\Message\ServerRequestInterface;

final readonly class StoaRequestFactory
{
    public function create(Request $request): ServerRequestInterface
    {
        $server = is_array($request->server) ? $request->server : [];
        $headers = is_array($request->header) ? $request->header : [];
        $query = is_array($request->get) ? $request->get : [];
        $cookies = is_array($request->cookie) ? $request->cookie : [];
        $parsedBody = is_array($request->post) ? $request->post : null;

        $method = strtoupper((string) ($server['request_method'] ?? 'GET'));
        $path = (string) ($server['request_uri'] ?? '/');
        $queryString = (string) ($server['query_string'] ?? '');
        $uri = $queryString === '' ? $path : "{$path}?{$queryString}";
        $protocol = str_replace('HTTP/', '', (string) ($server['server_protocol'] ?? '1.1'));
        $body = $request->fd > 0 && method_exists($request, 'rawContent')
            ? (string) $request->rawContent()
            : '';

        return (new ServerRequest($method, $uri, $headers, Utils::streamFor($body), $protocol, $server))
            ->withQueryParams($query)
            ->withCookieParams($cookies)
            ->withParsedBody($parsedBody);
    }
}

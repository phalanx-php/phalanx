<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use OpenSwoole\Http\Request;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final readonly class StoaRequestFactory
{
    public function create(Request $request): ServerRequestInterface
    {
        $server = self::arrayValue($request->server);
        $headers = self::arrayValue($request->header);
        $query = self::arrayValue($request->get);
        $files = self::arrayValue($request->files);
        $cookies = self::arrayValue($request->cookie);
        $post = self::arrayValue($request->post);
        $parsedBody = $post === [] ? null : $post;

        $method = strtoupper((string) ($server['request_method'] ?? 'GET'));
        $path = (string) ($server['request_uri'] ?? '/');
        $queryString = (string) ($server['query_string'] ?? '');
        $uri = $queryString === '' ? $path : "{$path}?{$queryString}";
        $protocol = str_replace('HTTP/', '', (string) ($server['server_protocol'] ?? '1.1'));
        $body = $request->fd > 0
            ? (string) $request->rawContent()
            : '';

        return (new ServerRequest($method, $uri, $headers, Utils::streamFor($body), $protocol, $server))
            ->withQueryParams($query)
            ->withCookieParams($cookies)
            ->withParsedBody($parsedBody)
            ->withUploadedFiles(self::uploadedFiles($files));
    }

    /** @return array<string, mixed> */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, UploadedFileInterface|array<int, UploadedFileInterface>>
     */
    private static function uploadedFiles(array $files): array
    {
        $uploaded = [];

        foreach ($files as $field => $file) {
            if (!is_array($file)) {
                continue;
            }

            if (is_array($file['tmp_name'] ?? null)) {
                $list = self::uploadedFileList($file);
                if ($list !== []) {
                    $uploaded[$field] = $list;
                }
                continue;
            }

            if (!is_string($file['tmp_name'] ?? null)) {
                continue;
            }

            $uploaded[$field] = self::uploadedFile($file);
        }

        return $uploaded;
    }

    /**
     * @param array<string, mixed> $file
     * @return array<int, UploadedFileInterface>
     */
    private static function uploadedFileList(array $file): array
    {
        $uploaded = [];
        $tmpNames = is_array($file['tmp_name'] ?? null) ? $file['tmp_name'] : [];
        $errors = is_array($file['error'] ?? null) ? $file['error'] : [];
        $names = is_array($file['name'] ?? null) ? $file['name'] : [];
        $sizes = is_array($file['size'] ?? null) ? $file['size'] : [];
        $types = is_array($file['type'] ?? null) ? $file['type'] : [];

        foreach ($tmpNames as $index => $tmpName) {
            if (!is_string($tmpName)) {
                continue;
            }

            $uploaded[] = self::uploadedFile([
                'error' => $errors[$index] ?? UPLOAD_ERR_OK,
                'name' => $names[$index] ?? null,
                'size' => $sizes[$index] ?? null,
                'tmp_name' => $tmpName,
                'type' => $types[$index] ?? null,
            ]);
        }

        return $uploaded;
    }

    /** @param array<string, mixed> $file */
    private static function uploadedFile(array $file): UploadedFileInterface
    {
        return new UploadedFile(
            $file['tmp_name'],
            (int) ($file['size'] ?? 0),
            (int) ($file['error'] ?? UPLOAD_ERR_OK),
            isset($file['name']) ? (string) $file['name'] : null,
            isset($file['type']) ? (string) $file['type'] : null,
        );
    }
}

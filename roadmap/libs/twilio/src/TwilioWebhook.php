<?php

declare(strict_types=1);

namespace Phalanx\Twilio;

use Psr\Http\Message\ServerRequestInterface;

final class TwilioWebhook
{
    public function __construct(
        private(set) TwilioConfig $config,
        private string $baseUrl = '',
    ) {}

    public function validate(ServerRequestInterface $request): bool
    {
        $signature = $request->getHeaderLine('X-Twilio-Signature');
        if ($signature === '') {
            return false;
        }

        $url = $this->buildUrl($request);
        $params = $this->extractParams($request);

        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key . (string) $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $this->config->authToken, true));

        return hash_equals($expected, $signature);
    }

    private function buildUrl(ServerRequestInterface $request): string
    {
        if ($this->baseUrl !== '') {
            return rtrim($this->baseUrl, '/') . $request->getUri()->getPath();
        }

        return (string) $request->getUri();
    }

    /** @return array<int|string, mixed> */
    private function extractParams(ServerRequestInterface $request): array
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $body = (string) $request->getBody();
            parse_str($body, $params);
            return $params;
        }

        return $request->getQueryParams();
    }
}

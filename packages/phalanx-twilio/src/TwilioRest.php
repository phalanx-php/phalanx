<?php

declare(strict_types=1);

namespace Phalanx\Twilio;

use Phalanx\Suspendable;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;

final class TwilioRest
{
    private Browser $browser;

    public function __construct(
        private TwilioConfig $config,
        private readonly Suspendable $scope,
    ) {
        $this->browser = new Browser()
            ->withTimeout(30.0)
            ->withFollowRedirects(false);
    }

    /** @return array<string, mixed> */
    public function createCall(string $to, string $from, string $url, ?string $statusCallback = null): array
    {
        $params = [
            'To' => $to,
            'From' => $from,
            'Url' => $url,
        ];

        if ($statusCallback !== null) {
            $params['StatusCallback'] = $statusCallback;
            $params['StatusCallbackEvent'] = 'initiated ringing answered completed';
        }

        return $this->post("/Accounts/{$this->config->accountSid}/Calls.json", $params);
    }

    /** @return array<string, mixed> */
    public function sendSms(string $to, string $from, string $body): array
    {
        return $this->post("/Accounts/{$this->config->accountSid}/Messages.json", [
            'To' => $to,
            'From' => $from,
            'Body' => $body,
        ]);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->config->apiBase . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $auth = base64_encode("{$this->config->accountSid}:{$this->config->authToken}");

        /** @var ResponseInterface $response */
        $response = $this->scope->await($this->browser->get($url, [
            'Authorization' => "Basic {$auth}",
        ]));

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new TwilioApiException($status, $body);
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    public function post(string $path, array $params): array
    {
        $url = $this->config->apiBase . $path;
        $auth = base64_encode("{$this->config->accountSid}:{$this->config->authToken}");

        /** @var ResponseInterface $response */
        $response = $this->scope->await($this->browser->post(
            $url,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => "Basic {$auth}",
            ],
            http_build_query($params),
        ));

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new TwilioApiException($status, $body);
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}

# convoy/integrations

Async clients for Claude, GPT, and Twilio — streaming AI responses, SMS, voice, and Conversation Relay — all non-blocking, all composable with Convoy scopes.

## Installation

```bash
composer require convoy/integrations
```

Requires PHP 8.4+.

## AI: Claude and GPT

### Setup

```php
use Convoy\Integrations\Ai\AiServiceBundle;

$app = Application::starting()
    ->providers(new AiServiceBundle())
    ->compile();
```

`AiServiceBundle` registers `ClaudeClient` and `GptClient` as singletons, configured through `AiConfig`.

### Configuration

```php
new AiConfig(
    claudeApiKey: 'sk-ant-...',
    gptApiKey: 'sk-...',
    claudeModel: 'claude-sonnet-4-20250514',
    gptModel: 'gpt-4o',
    maxTokens: 4096,
);
```

Falls back to `CLAUDE_API_KEY`, `GPT_API_KEY`, `CLAUDE_MODEL`, `GPT_MODEL` environment variables.

### Streaming Claude Responses

`ClaudeClient` streams via SSE, yielding `ClaudeStreamChunk` objects as they arrive:

```php
$claude = $scope->service(ClaudeClient::class);

$stream = $claude->stream([
    ClaudeMessage::user('Explain async PHP in 3 sentences'),
]);

foreach ($stream as $chunk) {
    echo $chunk->text; // Prints incrementally as tokens arrive
}
```

The stream is non-blocking. While tokens arrive from Claude, other tasks in the scope continue executing.

### Conversations with History

Build multi-turn conversations by passing message arrays:

```php
$stream = $claude->stream([
    ClaudeMessage::user('What is Convoy?'),
    ClaudeMessage::assistant('Convoy is an async coordination library for PHP 8.4+...'),
    ClaudeMessage::user('Show me a concurrent query example'),
]);
```

### Tool Use

Define tools that Claude can invoke during a conversation:

```php
$tools = [
    new ToolDefinition(
        name: 'lookup_order',
        description: 'Look up an order by ID',
        parameters: ['order_id' => ['type' => 'string', 'description' => 'The order ID']],
    ),
];

$stream = $claude->stream(
    messages: [ClaudeMessage::user('What is the status of order ORD-42?')],
    tools: $tools,
);

foreach ($stream as $chunk) {
    if ($chunk->toolCall instanceof ToolCall) {
        $result = handleToolCall($chunk->toolCall);
        // Continue conversation with tool result
    }
    echo $chunk->text;
}
```

### GPT Client

`GptClient` follows the same streaming pattern:

```php
$gpt = $scope->service(GptClient::class);

$stream = $gpt->stream([
    ['role' => 'user', 'content' => 'Summarize this document'],
]);

foreach ($stream as $chunk) {
    echo $chunk->text;
}
```

### Concurrent AI Calls

Run multiple AI requests concurrently — useful for comparing models or running analysis side-by-side:

```php
[$claudeResult, $gptResult] = $scope->concurrent([
    Task::of(static fn($s) => collectStream($s->service(ClaudeClient::class)->stream($messages))),
    Task::of(static fn($s) => collectStream($s->service(GptClient::class)->stream($messages))),
]);
```

## Twilio: SMS, Voice, and Conversation Relay

### Setup

```php
use Convoy\Integrations\Twilio\TwilioServiceBundle;

$app = Application::starting()
    ->providers(new TwilioServiceBundle())
    ->compile();
```

`TwilioServiceBundle` registers `TwilioRest` and webhook handling infrastructure, configured through `TwilioConfig`.

### Configuration

```php
new TwilioConfig(
    accountSid: 'AC...',
    authToken: '...',
);
```

Falls back to `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN` environment variables.

### Sending SMS

```php
$twilio = $scope->service(TwilioRest::class);

$twilio->sendMessage(
    to: '+1234567890',
    body: 'Your order has shipped!',
    from: '+1987654321',
);
```

### Voice Webhooks with TwiML

Handle incoming calls and build voice responses:

```php
Route::create('POST', '/twilio/voice', static function (ExecutionScope $scope): Response {
    $twiml = TwiML::say('Welcome to Convoy. Press 1 for orders, press 2 for support.')
        ->gather(numDigits: 1, action: '/twilio/menu');

    return Response::xml($twiml);
});

Route::create('POST', '/twilio/menu', static function (ExecutionScope $scope): Response {
    $digit = $scope->request()->get('Digits');

    return match ($digit) {
        '1' => Response::xml(TwiML::say('Transferring to orders.')->dial('+1111111111')),
        '2' => Response::xml(TwiML::say('Transferring to support.')->dial('+1222222222')),
        default => Response::xml(TwiML::say('Invalid selection. Goodbye.')),
    };
});
```

### Webhook Validation

`TwilioWebhook` validates incoming request signatures to confirm they originate from Twilio:

```php
Route::create('POST', '/twilio/sms', static function (ExecutionScope $scope): Response {
    $webhook = TwilioWebhook::fromRequest($scope->request());

    if (!$webhook->isValid()) {
        return Response::forbidden();
    }

    $body = $webhook->param('Body');
    // Process incoming SMS
});
```

### Conversation Relay

`ConversationRelay` implements Twilio's Conversation Relay protocol for building real-time AI voice agents over WebSocket:

```php
$relay = $scope->service(ConversationRelay::class);

$relay->onMessage(static function (CrMessage $message, CrProtocol $protocol) {
    // User spoke — $message contains the transcribed text
    $response = generateAiResponse($message->text);

    // Send response back — Twilio speaks it to the caller
    $protocol->send(CrMessage::say($response));
});
```

The protocol handles the WebSocket framing, turn detection, and text-to-speech coordination. Your code receives transcribed text and sends back strings to be spoken.

### Concurrent Twilio Operations

Send bulk messages without blocking:

```php
$scope->concurrent(array_map(
    static fn($recipient) => Task::of(
        static fn($s) => $s->service(TwilioRest::class)->sendMessage(
            to: $recipient->phone,
            body: "Your appointment is tomorrow at {$recipient->time}",
        )
    ),
    $recipients,
));
```

<p align="center">
  <img src="https://raw.githubusercontent.com/havy-tech/phalanx/main/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/twilio

Async Twilio integration for Phalanx -- SMS, voice calls, webhook validation, TwiML building, and Conversation Relay protocol support. Built on ReactPHP for non-blocking I/O.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [SMS](#sms)
- [Voice Calls](#voice-calls)
- [TwiML](#twiml)
- [Webhook Validation](#webhook-validation)
- [Conversation Relay](#conversation-relay)
- [REST Client](#rest-client)

## Installation

```bash
composer require phalanx/twilio
```

Requires PHP 8.4+, `phalanx/core`, `react/http`.

## Quick Start

```php
<?php

use Phalanx\Twilio\TwilioServiceBundle;

$app = Application::starting([
    'twilio_account_sid' => getenv('TWILIO_ACCOUNT_SID'),
    'twilio_auth_token' => getenv('TWILIO_AUTH_TOKEN'),
])
    ->providers(new TwilioServiceBundle())
    ->compile();

$scope = $app->createScope();
$twilio = $scope->service(TwilioRest::class);

$twilio->sendSms(
    to: '+15551234567',
    from: '+15559876543',
    body: 'Your order has shipped!',
);
```

## Configuration

`TwilioServiceBundle` registers `TwilioRest` and `TwilioWebhook` as singletons. Pass credentials through the application context:

```php
<?php

use Phalanx\Twilio\TwilioConfig;

$config = new TwilioConfig(
    accountSid: 'ACxxxxxxxx',
    authToken: 'your-auth-token',
    apiBase: 'https://api.twilio.com/2010-04-01', // default
);
```

## SMS

```php
<?php

$result = $twilio->sendSms(
    to: '+15551234567',
    from: '+15559876543',
    body: 'Your verification code is 847291',
);

// $result contains the Twilio API response as an associative array
echo $result['sid']; // Message SID
```

## Voice Calls

```php
<?php

$result = $twilio->createCall(
    to: '+15551234567',
    from: '+15559876543',
    url: 'https://your-app.com/voice/welcome',
    statusCallback: 'https://your-app.com/voice/status', // optional
);
```

The `statusCallback` receives events for `initiated`, `ringing`, `answered`, and `completed`.

## TwiML

Build TwiML responses with a fluent API:

```php
<?php

use Phalanx\Twilio\TwiML;

$response = TwiML::response()
    ->say('Welcome to Acme Support.', voice: 'Polly.Amy')
    ->pause(1)
    ->say('Please hold while we connect you.')
    ->build();

// Returns: <?xml version="1.0" encoding="UTF-8"?><Response><Say voice="Polly.Amy">Welcome...</Say>...</Response>
```

### Conversation Relay

Connect a Twilio voice call to a real-time AI agent over WebSocket:

```php
<?php

$response = TwiML::response()
    ->conversationRelay(
        url: 'wss://your-app.com/ws/voice-agent',
        welcomeGreeting: 'Hello! How can I help you today?',
        ttsProvider: 'Amazon',
        transcriptionProvider: 'Deepgram',
        voice: 'Polly.Amy',
        language: 'en-US',
    )
    ->build();
```

Parse incoming Conversation Relay messages:

```php
<?php

use Phalanx\Twilio\ConversationRelay\CrProtocol;
use Phalanx\Twilio\ConversationRelay\CrMessage;

// Inside a WebSocket handler
$msg = CrMessage::fromJson($rawJson);

if ($msg->type === 'prompt') {
    // User spoke -- send to AI agent, stream response back
    $conn->send(CrProtocol::text('Let me check that for you...', last: false));
    $conn->send(CrProtocol::text(' I found the answer.', last: true));
}

// Other protocol messages
CrProtocol::endSession(handoffData: '{"reason":"transfer"}');
CrProtocol::sendDigits('1234#');
CrProtocol::play('https://cdn.example.com/hold-music.mp3');
CrProtocol::language('es-ES', transcriptionLanguage: 'es-ES');
```

## Webhook Validation

Validate incoming Twilio webhooks using HMAC-SHA1 signature verification:

```php
<?php

use Phalanx\Twilio\TwilioWebhook;

$webhook = $scope->service(TwilioWebhook::class);

$isValid = $webhook->validate($request); // PSR-7 ServerRequestInterface

if (!$isValid) {
    return new Response(403, [], 'Invalid signature');
}
```

When behind a reverse proxy, pass `base_url` through the application context so the signature computation uses the public URL:

```php
<?php

$app = Application::starting([
    'twilio_account_sid' => getenv('TWILIO_ACCOUNT_SID'),
    'twilio_auth_token' => getenv('TWILIO_AUTH_TOKEN'),
    'base_url' => 'https://your-app.com',
]);
```

## REST Client

`TwilioRest` exposes `get()` and `post()` for any Twilio API endpoint:

```php
<?php

// Fetch call details
$call = $twilio->get("/Accounts/{$sid}/Calls/{$callSid}.json");

// Any POST endpoint
$result = $twilio->post("/Accounts/{$sid}/Messages.json", [
    'To' => '+15551234567',
    'From' => '+15559876543',
    'Body' => 'Custom message',
]);
```

All HTTP calls are non-blocking via ReactPHP. Errors throw `TwilioApiException` with the HTTP status code and response body.

# Support Ticket Triage

A single agent classifies support tickets, gathers customer context, and drafts a response -- all streaming to the support agent's browser in real time via SSE.

## Non-server demo

Runs one triage cycle with a scripted ticket payload and exits 0. No live provider or network required.

```bash
php -d extension=swoole demos/athena/support-triage/demo.php
# or via composer:
composer demo:athena:support-triage
```

## HTTP server

Starts an SSE endpoint at `POST /triage`. The handler runs the triage agent and streams cue events back to the caller.

```bash
composer demo:athena:serve:support-triage
# or with a specific Ollama model:
OLLAMA_MODEL=qwen2.5-coder:7b php -d extension=swoole demos/athena/support-triage/server.php
```

Submit a ticket:

```bash
curl -N -X POST http://localhost:8080/triage -H 'Content-Type: application/json' -d '{"customer_email":"hoplite@sparta.polis","subject":"Aspis delivery delay","body":"My shield order has not arrived before the battle at Thermopylae."}'
```

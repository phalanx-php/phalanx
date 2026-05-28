# Phalanx Agentic (POC)

Agentic UI runtime and demo for Phalanx. See the main monorepo:
https://github.com/phalanx-php/phalanx

- **AgentSession** — one supervised coroutine + memory per conversation
- **Live Typed Signals** — Thinking, ToolCall, ToolResult, FinalAnswer, UiIntent, Branch
- **ConversationSupervisor** — global admin feed of all active runs
- **ComposerHandler** — slash commands, attachments, tool approvals over WS

## Usage Example

```php
$session = $registry->resumeOrCreate('conv-42', MySupportAgent::class);
$session->handleUserMessage($scope, 'Why is the TPS report late?');
```

## WebSocket

Connect to `/agent-ws` and send:
```json
{"session_id":"conv-42","message":"/approve tool_call_3"}
```

## Roadmap

This POC will graduate into `packages/phalanx-eidolon-agentic` once validated.

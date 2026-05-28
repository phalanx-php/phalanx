# Agentic Oversight Demo

Live global admin dashboard + per-session Slack-style agent composer using the `phalanx-agentic` library.

## Run

```bash
cd poc/agentic-oversight-demo
composer install
php server.php
```

Open:
- `http://localhost:8090/admin/agent-oversight` → Global feed
- WS endpoint: `ws://localhost:8090/agent-ws`

## What you see

- Live list of all agent conversations in the "global" workspace
- Real-time status, token counts, pending tool approvals
- Click a session → open detail view with live thinking + composer
- Use slash commands inside the composer (future)

## Cancellation

Cancel buttons emit `UiIntentSignal` (pause/cancel/branch). The Aegis scope tree is cleaned automatically on disconnect.

## Next

When this POC proves the model, the entire library moves into the main Phalanx monorepo.

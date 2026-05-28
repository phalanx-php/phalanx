# Phalanx-on-Swoole MVP

Proving-ground for the substrate redesign. See parent project context.

## Requirements

- PHP 8.4+
- ext-openswoole (real Swoole 6 preferred; OpenSwoole used here as a stand-in,
  cancellation-sensitive paths flagged for re-verification)

## Run the demo

```
/opt/homebrew/bin/php demo/transfer-demo.php
```

## What the demo proves

Three Writes tasks fanned out from one Composes orchestrator. Two share a
keyed resource (`Account` keyed by `accountId`); one is non-overlapping.
Observable timestamps show the overlapping pair serializes while the
non-overlapping task runs concurrently with both.

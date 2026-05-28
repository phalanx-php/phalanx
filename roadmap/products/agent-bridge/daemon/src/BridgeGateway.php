<?php

declare(strict_types=1);

namespace AgentBridge;

use AgentBridge\Tab\TabManager;
use Phalanx\Task\Scopeable;
use Phalanx\Hermes\WsConnection;
use Phalanx\Hermes\WsContext;

final class BridgeGateway implements Scopeable
{
    public function __invoke(\Phalanx\Scope $ctx): mixed
    {
        assert($ctx instanceof WsContext);
        $conn = $ctx->connection;
        $tabManager = $ctx->service(TabManager::class);
        $session = new ExtensionSession($conn);

        $tabManager->registerSession($session);

        $ctx->onDispose(static function () use ($tabManager, $session): void {
            $tabManager->unregisterSession($session);
        });

        self::pump($ctx, $conn, $tabManager, $session);

        return null;
    }

    private static function pump(
        WsContext $ctx,
        WsConnection $conn,
        TabManager $tabManager,
        ExtensionSession $session,
    ): void {
        foreach ($conn->inbound->consume() as $frame) {
            $ctx->throwIfCancelled();

            try {
                $data = json_decode($frame->payload, true, 2048, JSON_THROW_ON_ERROR);
                $msg = BridgeMessage::fromJson($data);
            } catch (\Throwable) {
                continue;
            }

            self::routeMessage($msg, $tabManager, $session);
        }
    }

    /**
     * Route a parsed message to the correct TabManager handler.
     *
     * Extracted as public static to allow direct unit testing of the routing
     * invariant: dom.response MUST match before the dom.* prefix so it is
     * delivered to its pending Deferred, not emitted into the stream pipeline.
     */
    public static function routeMessage(BridgeMessage $msg, TabManager $tabManager, ExtensionSession $session): void
    {
        // dom.response MUST precede the dom.* prefix match -- it is a request-reply,
        // not a stream event, and must be routed to its pending Deferred by requestId.
        match (true) {
            $msg->type === 'dom.response'              => $tabManager->handleDomResponse($msg, $session),
            str_starts_with($msg->type, 'tab.')        => $tabManager->handleTabMessage($msg, $session),
            str_starts_with($msg->type, 'dom.')        => $tabManager->handleDomMessage($msg, $session),
            str_starts_with($msg->type, 'net.')        => $tabManager->handleNetMessage($msg, $session),
            str_starts_with($msg->type, 'user.')       => $tabManager->handleUserMessage($msg, $session),
            str_starts_with($msg->type, 'action.')     => $tabManager->handleActionResult($msg, $session),
            str_starts_with($msg->type, 'flow.')       => $tabManager->handleFlowControl($msg, $session),
            default                                    => null,
        };
    }
}

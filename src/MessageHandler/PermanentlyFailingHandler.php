<?php

declare(strict_types=1);

namespace App\MessageHandler;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  RABBITMQ EXAMPLE 3 — DEAD-LETTER / FAILED QUEUE PATH                  │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  This handler always throws an UnrecoverableMessageHandlingException.   │
 * │                                                                         │
 * │  Two exception types matter here:                                       │
 * │                                                                         │
 * │  RecoverableMessageHandlingException  → Messenger retries               │
 * │  UnrecoverableMessageHandlingException → Messenger skips retries,       │
 * │                                          moves straight to failed queue │
 * │  Any other \Throwable                 → Messenger retries until         │
 * │                                          max_retries, then failed queue │
 * │                                                                         │
 * │  After landing in the failed queue you can:                             │
 * │    # List failed messages                                               │
 * │    docker compose exec app php bin/console messenger:failed:show        │
 * │                                                                         │
 * │    # Inspect one message (e.g. id 1)                                   │
 * │    docker compose exec app php bin/console messenger:failed:show 1 -vv │
 * │                                                                         │
 * │    # Retry a specific message                                           │
 * │    docker compose exec app php bin/console messenger:failed:retry 1    │
 * │                                                                         │
 * │    # Retry ALL failed messages                                          │
 * │    docker compose exec app php bin/console messenger:failed:retry       │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use App\Message\PermanentlyFailingMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class PermanentlyFailingHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PermanentlyFailingMessage $message): void
    {
        $this->logger->error('[FAILING] Handler invoked — this will always fail.', [
            'reason'    => $message->reason,
            'queued_at' => $message->queuedAt->format('H:i:s'),
        ]);

        // UnrecoverableMessageHandlingException bypasses the retry strategy:
        // Messenger immediately moves this message to the `failed` transport
        // (messages_failed queue in RabbitMQ) without any retry attempts.
        //
        // Use this when you know retrying makes no sense:
        //   - Broken config / missing env var
        //   - Invalid data that passed validation but is logically wrong
        //   - External service permanently gone
         throw new UnrecoverableMessageHandlingException(
             '[FAILING] Unrecoverable error: ' . $message->reason
         );
    }
}

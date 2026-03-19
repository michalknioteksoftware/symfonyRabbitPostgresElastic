<?php

declare(strict_types=1);

namespace App\Message;

/**
 * DEAD-LETTER PATH — always throws, exhausts all retries, lands in "failed" queue.
 *
 * Simulates a handler that can never succeed: broken integration, missing config,
 * or a bug that needs a deploy to fix.
 *
 * After max_retries (3) attempts the message is moved to the `messages_failed`
 * queue (dead-letter queue) by Symfony Messenger.  You can inspect it with:
 *
 *   docker compose exec app php bin/console messenger:failed:show
 *   docker compose exec app php bin/console messenger:failed:retry
 *
 * Or view the "messages_failed" queue in the RabbitMQ Management UI.
 *
 * Real-world uses: catching unrecoverable errors so they are never silently lost.
 */
final class PermanentlyFailingMessage
{
    public function __construct(
        public readonly string $reason,
        public readonly \DateTimeImmutable $queuedAt = new \DateTimeImmutable(),
    ) {
    }
}

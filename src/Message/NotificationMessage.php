<?php

declare(strict_types=1);

namespace App\Message;

/**
 * HAPPY PATH — always succeeds.
 *
 * Simulates sending a notification (e-mail, Slack, push…).
 * The handler logs the work and returns without throwing,
 * so the message is acknowledged and removed from the queue immediately.
 *
 * Real-world uses: welcome emails, password-reset links, order confirmations.
 */
final class NotificationMessage
{
    public function __construct(
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $body,
        public readonly \DateTimeImmutable $queuedAt = new \DateTimeImmutable(),
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  RABBITMQ EXAMPLE 1 — HAPPY PATH                                        │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  This handler always succeeds.                                          │
 * │                                                                         │
 * │  What happens in RabbitMQ:                                              │
 * │  1. Message arrives in "messages" queue                                 │
 * │  2. Worker picks it up and calls __invoke()                             │
 * │  3. Handler does its work, returns normally                             │
 * │  4. Messenger sends an ACK → RabbitMQ deletes the message              │
 * │                                                                         │
 * │  ACK (acknowledgement) = "I'm done, remove this from the queue."       │
 * │  NACK (negative ack)   = "I failed, put it back / dead-letter it."     │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use App\Message\NotificationMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NotificationHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotificationMessage $message): void
    {
        // Simulate the time a real email/push/Slack call takes.
        usleep(200_000); // 200 ms

        // In a real app you would inject Symfony Mailer / an HTTP client here.
        $this->logger->info('[NOTIFICATION] Message sent.', [
            'to'        => $message->recipient,
            'subject'   => $message->subject,
            'body'      => substr($message->body, 0, 80),
            'queued_at' => $message->queuedAt->format('H:i:s'),
            'lag_ms'    => (int) ((new \DateTimeImmutable())->format('Uv'))
                           - (int) $message->queuedAt->format('Uv'),
        ]);

        // Returning normally = success.
        // Messenger will ACK the message → it disappears from the queue.
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  RABBITMQ EXAMPLE 2 — FLAKY / RETRY PATH                               │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  This handler fails on the first two delivery attempts and succeeds     │
 * │  on the third.                                                          │
 * │                                                                         │
 * │  What happens in RabbitMQ (with max_retries: 3, multiplier: 2):        │
 * │  Attempt 1 → throws → NACK → requeued, wait ~1 s                       │
 * │  Attempt 2 → throws → NACK → requeued, wait ~2 s                       │
 * │  Attempt 3 → succeeds → ACK → message removed                          │
 * │                                                                         │
 * │  Messenger stamps:                                                      │
 * │  Every redelivery adds a RedeliveryStamp to the envelope so the         │
 * │  handler can inspect how many times it has been tried already.         │
 * │                                                                         │
 * │  How to watch it:                                                       │
 * │  docker compose logs -f worker                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use App\Message\ReportGenerationMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
final class ReportGenerationHandler
{
    // ── Why a static array instead of RedeliveryStamp? ────────────────────────
    // Symfony Messenger only passes the message object to __invoke() — stamps
    // live on the Envelope and are NOT forwarded to the handler.
    // A static array works because the worker is a single long-running PHP
    // process: the same class instance handles every retry of the same message
    // within that process, so the counter survives across re-deliveries.
    // Key = reportType + microsecond timestamp set at dispatch time (serialised
    // with the message, therefore identical on every retry attempt).
    private static array $attempts = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReportGenerationMessage $message): void
    {
        // Build a key that uniquely identifies this specific dispatched message.
        $key = $message->reportType . '_' . $message->queuedAt->format('Uu');

        self::$attempts[$key] = (self::$attempts[$key] ?? 0) + 1;
        $attempt = self::$attempts[$key];

        $this->logger->info('[REPORT] Attempt #{attempt} for report "{type}".', [
            'attempt' => $attempt,
            'type'    => $message->reportType,
        ]);

        if ($attempt <= 2) {
            // RecoverableMessageHandlingException = "transient problem, please retry".
            // A plain \RuntimeException has the same effect — Messenger will
            // requeue with exponential back-off up to max_retries (3).
            throw new RecoverableMessageHandlingException(
                sprintf('[REPORT] Transient failure on attempt %d — will retry.', $attempt)
            );
        }

        // ── Third attempt: simulate successful report generation ───────────────
        unset(self::$attempts[$key]); // clean up so memory doesn't grow
        usleep(500_000);              // 500 ms of "heavy work"

        $this->logger->info('[REPORT] Report "{type}" generated successfully on attempt #{attempt}.', [
            'type'    => $message->reportType,
            'attempt' => $attempt,
            'user_id' => $message->requestedBy,
        ]);
    }
}

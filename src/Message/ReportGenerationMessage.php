<?php

declare(strict_types=1);

namespace App\Message;

/**
 * FLAKY PATH — fails on the first two attempts, succeeds on the third.
 *
 * Simulates a report generator that hits a temporarily unavailable resource
 * (slow DB query, external API timeout, file-system lock, etc.).
 *
 * Messenger's retry_strategy in messenger.yaml retries up to 3 times with
 * exponential back-off.  Because this handler fails twice and then succeeds,
 * you will see the message requeued in RabbitMQ twice before it disappears.
 *
 * Real-world uses: PDF generation, data exports, heavy aggregation queries.
 */
final class ReportGenerationMessage
{
    public function __construct(
        public readonly string $reportType,
        public readonly int    $requestedBy,
        public readonly \DateTimeImmutable $queuedAt = new \DateTimeImmutable(),
    ) {
    }
}

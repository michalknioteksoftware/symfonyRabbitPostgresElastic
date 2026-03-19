<?php

declare(strict_types=1);

namespace App\Message;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  FEATURE: SYMFONY MESSENGER — MESSAGE                                   │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  A Message is a plain PHP object (no base class needed). It carries     │
 * │  everything the Handler needs to do its work asynchronously.            │
 * │                                                                         │
 * │  Messenger workflow:                                                    │
 * │  1. Controller dispatches message → MessageBus → RabbitMQ              │
 * │  2. Consumer worker picks it up  → deserializes → calls Handler        │
 * │  3. Handler does the work        → indexes in ES, sends email, etc.    │
 * │                                                                         │
 * │  The message must be serializable (JSON/PHP). Keep it small —          │
 * │  store only IDs, not full objects, so serialization is cheap and        │
 * │  the handler fetches fresh data from the DB when it runs.              │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

/**
 * Dispatched when an article is published.
 * Triggers background work: Elasticsearch indexing, cache warming, etc.
 */
final class ArticlePublishedMessage
{
    public function __construct(
        // Pass only the ID — the handler fetches the full entity from DB.
        // This avoids serializing a large object and ensures the handler
        // always works with the latest data (not a stale snapshot).
        public readonly int    $articleId,
        public readonly string $title,
        public readonly \DateTimeImmutable $publishedAt = new \DateTimeImmutable(),
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  FEATURE: SYMFONY MESSENGER — HANDLER                                   │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  A Handler contains the business logic for one Message type.            │
 * │  #[AsMessageHandler] + __invoke(MessageClass) is all Symfony needs —   │
 * │  no routing config required (the type-hint does the wiring).            │
 * │                                                                         │
 * │  This handler runs inside the worker process, completely separate       │
 * │  from the HTTP request. Start the worker with:                          │
 * │  docker compose exec app php bin/console messenger:consume async -vv   │
 * │                                                                         │
 * │  Retry logic: if the handler throws, Messenger re-queues the message    │
 * │  up to max_retries times with exponential backoff (see messenger.yaml). │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use App\Message\ArticlePublishedMessage;
use App\Repository\ArticleRepository;
use App\Service\ElasticsearchService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ArticlePublishedHandler
{
    public function __construct(
        private readonly ArticleRepository    $articles,
        private readonly ElasticsearchService $elasticsearch,
        private readonly LoggerInterface      $logger,
    ) {
    }

    // __invoke() is called by the Messenger worker with the deserialized message.
    // The type-hint on $message tells Symfony which message class to handle.
    public function __invoke(ArticlePublishedMessage $message): void
    {
        $this->logger->info('Handling ArticlePublishedMessage', [
            'article_id' => $message->articleId,
            'title'      => $message->title,
        ]);

        // ── Fetch fresh data ───────────────────────────────────────────────────
        // Always fetch from DB in the handler — the entity might have changed
        // between dispatch time and when the worker processes the message.
        $article = $this->articles->find($message->articleId);

        if ($article === null) {
            // The article was deleted between dispatch and handling.
            // Log and return — do NOT throw, or Messenger will retry forever.
            $this->logger->warning('Article not found, skipping index.', [
                'article_id' => $message->articleId,
            ]);
            return;
        }

        // ── Index in Elasticsearch ─────────────────────────────────────────────
        // This runs in the background so it does not block the HTTP response.
        // If Elasticsearch is down, Messenger retries (see messenger.yaml).
        try {
            $this->elasticsearch->index('articles', (string) $article->getId(), [
                'id'          => $article->getId(),
                'title'       => $article->getTitle(),
                'slug'        => $article->getSlug(),
                'content'     => $article->getContent(),
                'tags'        => $article->getTags(),
                'published_at'=> $article->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            ]);

            $this->logger->info('Article indexed in Elasticsearch.', [
                'article_id' => $message->articleId,
            ]);
        } catch (\Throwable $e) {
            // Re-throw so Messenger knows the job failed and will retry.
            $this->logger->error('Failed to index article in Elasticsearch.', [
                'article_id' => $message->articleId,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

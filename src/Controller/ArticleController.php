<?php

declare(strict_types=1);

namespace App\Controller;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  FEATURES: CONTROLLER · ROUTING · DI · CACHE · MESSENGER · SERIALIZER  │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  This single controller demonstrates the most common Symfony patterns:  │
 * │                                                                         │
 * │  ROUTING          #[Route] attribute on class + methods                 │
 * │  DEPENDENCY INJ.  Constructor injection of any registered service       │
 * │  SERIALIZER       $this->json() with serializer groups                  │
 * │  VALIDATION       #[MapRequestPayload] + manual ValidatorInterface      │
 * │  CACHE            CacheInterface pool backed by Redis                   │
 * │  MESSENGER        MessageBusInterface → async RabbitMQ processing       │
 * │  HTTP FOUNDATION  Request / JsonResponse / typed HTTP status codes      │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use App\Dto\ArticleInput;
use App\Entity\Article;
use App\Message\ArticlePublishedMessage;
use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

// ── Routing ────────────────────────────────────────────────────────────────
// A #[Route] on the class sets a common URL prefix for all methods below.
// This avoids repeating '/api/articles' on every method.
#[Route('/api/articles', name: 'article_')]
final class ArticleController extends AbstractController
{
    // ── Dependency Injection ───────────────────────────────────────────────────
    // Symfony's DI container reads the constructor type-hints and injects the
    // correct services automatically — no factory code or service locator needed.
    // Constructor injection is the recommended approach: dependencies are explicit
    // and the class is easy to unit-test (just pass mocks in the constructor).
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly CacheInterface    $cache,
        private readonly MessageBusInterface $bus,
    ) {
    }

    // ── LIST ───────────────────────────────────────────────────────────────────
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(50, max(1, $request->query->getInt('per_page', 10)));

        // ── Cache (Redis) ──────────────────────────────────────────────────────
        // CacheInterface::get() returns the cached value or calls the callback
        // to compute + store it. The key includes pagination so each page is
        // cached separately. The backing adapter is Redis (see framework.yaml).
        $cacheKey = sprintf('articles_list_p%d_pp%d', $page, $perPage);

        $data = $this->cache->get($cacheKey, function (ItemInterface $item) use ($page, $perPage): array {
            $item->expiresAfter(60); // cache for 60 seconds

            $paginator = $this->articles->findPublished($page, $perPage);

            return [
                'data'  => iterator_to_array($paginator),
                'meta'  => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => count($paginator),
                    'total_pages' => (int) ceil(count($paginator) / $perPage),
                ],
            ];
        });

        // ── Serializer groups ──────────────────────────────────────────────────
        // The third argument of json() is passed to the Symfony Serializer.
        // 'groups' controls which #[Groups] properties are included in the JSON.
        // Here only properties tagged #[Groups(['article:read'])] are serialized.
        return $this->json($data, Response::HTTP_OK, [], ['groups' => ['article:read']]);
    }

    // ── SHOW ───────────────────────────────────────────────────────────────────
    // Route parameters are captured with {id} and injected as method arguments.
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $article = $this->cache->get(
            'article_'.$id,
            function (ItemInterface $item) use ($id): ?Article {
                $item->expiresAfter(120);
                return $this->articles->find($id);
            }
        );

        if (!$article instanceof Article) {
            // Return a structured 404 — never expose internal exceptions to clients.
            return $this->json(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($article, Response::HTTP_OK, [], ['groups' => ['article:read']]);
    }

    // ── CREATE ─────────────────────────────────────────────────────────────────
    // #[MapRequestPayload] deserializes the JSON body into ArticleInput and
    // runs the Validator. If validation fails, Symfony returns a 422 response
    // automatically — there is nothing to write in this method to handle that.
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] ArticleInput $input): JsonResponse
    {
        $article = (new Article())
            ->setTitle($input->title)
            ->setContent($input->content)
            ->setTags($input->tags);

        // persist() tells Doctrine to track the object.
        // flush() executes the INSERT SQL. Separating them allows batching
        // multiple persist() calls into a single transaction.
        $this->articles->save($article);

        // Invalidate the list cache so the new article appears immediately.
        $this->cache->delete('articles_list_p1_pp10');

        return $this->json($article, Response::HTTP_CREATED, [], ['groups' => ['article:read']]);
    }

    // ── UPDATE ─────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] ArticleInput $input): JsonResponse
    {
        $article = $this->articles->find($id);
        if (!$article) {
            return $this->json(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
        }

        $article->setTitle($input->title)
                ->setContent($input->content)
                ->setTags($input->tags);

        $this->articles->save($article);

        // Invalidate both the list cache and this article's individual cache entry.
        $this->cache->delete('articles_list_p1_pp10');
        $this->cache->delete('article_'.$id);

        return $this->json($article, Response::HTTP_OK, [], ['groups' => ['article:read']]);
    }

    // ── DELETE ─────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $article = $this->articles->find($id);
        if (!$article) {
            return $this->json(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->articles->remove($article);
        $this->cache->delete('article_'.$id);
        $this->cache->delete('articles_list_p1_pp10');

        // 204 No Content is the correct response for a successful DELETE.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // ── PUBLISH ────────────────────────────────────────────────────────────────
    // Demonstrates Symfony Messenger: heavy work is offloaded to a background
    // worker (RabbitMQ consumer) so the HTTP response returns instantly.
    #[Route('/{id}/publish', name: 'publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id): JsonResponse
    {
        $article = $this->articles->find($id);
        if (!$article) {
            return $this->json(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($article->isPublished()) {
            return $this->json(['error' => 'Article is already published.'], Response::HTTP_CONFLICT);
        }

        // Mark as published and persist immediately.
        $article->publish();
        $this->articles->save($article);
        $this->cache->delete('article_'.$id);

        // ── Messenger dispatch ─────────────────────────────────────────────────
        // dispatch() puts the message on the RabbitMQ 'async' transport and
        // returns instantly. The ArticlePublishedHandler runs in the background
        // worker process (php bin/console messenger:consume async).
        // Use this for anything that does not need to block the HTTP response:
        // sending emails, indexing search engines, generating thumbnails, etc.
        $this->bus->dispatch(new ArticlePublishedMessage($id, $article->getTitle()));

        return $this->json(
            ['message' => 'Article published. Background tasks dispatched.'],
            Response::HTTP_ACCEPTED // 202 = accepted but processing is async
        );
    }

    // ── SEARCH ─────────────────────────────────────────────────────────────────
    // Falls back to SQL LIKE search; replace with ElasticsearchService for prod.
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        if (strlen($term) < 2) {
            return $this->json(['error' => 'Search term must be at least 2 characters.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $results = $this->articles->search($term);

        return $this->json(
            ['data' => $results, 'meta' => ['count' => count($results), 'term' => $term]],
            Response::HTTP_OK,
            [],
            ['groups' => ['article:read']]
        );
    }
}

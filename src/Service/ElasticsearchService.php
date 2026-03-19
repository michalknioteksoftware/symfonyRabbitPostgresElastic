<?php

declare(strict_types=1);

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;

class ElasticsearchService
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Check if Elasticsearch is reachable.
     */
    public function ping(): bool
    {
        return $this->client->ping()->asBool();
    }

    /**
     * Index (create or update) a document.
     */
    public function index(string $index, string $id, array $document): array
    {
        return $this->client->index([
            'index' => $index,
            'id'    => $id,
            'body'  => $document,
        ])->asArray();
    }

    /**
     * Retrieve a document by ID.
     */
    public function get(string $index, string $id): array
    {
        return $this->client->get([
            'index' => $index,
            'id'    => $id,
        ])->asArray();
    }

    /**
     * Delete a document by ID.
     */
    public function delete(string $index, string $id): array
    {
        return $this->client->delete([
            'index' => $index,
            'id'    => $id,
        ])->asArray();
    }

    /**
     * Run a search query against an index.
     *
     * @param array $query Elasticsearch query DSL body
     */
    public function search(string $index, array $query): array
    {
        return $this->client->search([
            'index' => $index,
            'body'  => $query,
        ])->asArray();
    }

    /**
     * Create an index with optional mappings and settings.
     */
    public function createIndex(string $index, array $mappings = [], array $settings = []): array
    {
        $params = ['index' => $index];

        if ($mappings || $settings) {
            $params['body'] = array_filter([
                'mappings' => $mappings ?: null,
                'settings' => $settings ?: null,
            ]);
        }

        return $this->client->indices()->create($params)->asArray();
    }

    /**
     * Check whether an index exists.
     */
    public function indexExists(string $index): bool
    {
        try {
            return $this->client->indices()->exists(['index' => $index])->asBool();
        } catch (ClientResponseException | ServerResponseException) {
            return false;
        }
    }

    /**
     * Full-text search across indexed articles (published only).
     *
     * Uses multi_match across title (3×), tags (2×), and content with
     * AUTO fuzziness so minor typos still return results.
     * Highlights are returned for title and content so the UI can show
     * which part of the document matched.
     *
     * Returns a normalised array:
     * [
     *   'hits'  => [ ['id', 'title', 'slug', 'tags', 'published_at', 'score', 'highlights'], ... ],
     *   'total' => int,
     *   'error' => string|null,   // non-null when ES is unavailable or index missing
     * ]
     */
    public function searchArticles(string $term, int $size = 20): array
    {
        // Gracefully handle a missing index (e.g. no articles published yet).
        if (!$this->indexExists('articles')) {
            return ['hits' => [], 'total' => 0, 'error' => 'Index not ready — publish at least one article first.'];
        }

        try {
            $response = $this->search('articles', [
                'size'    => $size,
                '_source' => ['id', 'title', 'slug', 'tags', 'published_at'],
                'query'   => [
                    'bool' => [
                        // Both clauses are optional (should), but at least one must match.
                        // This gives us two complementary strategies in a single query:
                        'should' => [
                            // ① Fuzzy multi_match — handles full words with typos.
                            //   "symfoni" → "symfony" (1 edit), "geting" → "getting" (1 edit).
                            //   AUTO allows 1 edit for words ≤ 5 chars, 2 edits for longer ones.
                            //   Does NOT handle partial prefixes: "Get" ≠ "Getting".
                            [
                                'multi_match' => [
                                    'query'          => $term,
                                    'fields'         => ['title^3', 'tags^2', 'content'],
                                    'fuzziness'      => 'AUTO',
                                    'prefix_length'  => 1,  // first char must match to avoid too-broad results
                                ],
                            ],
                            // ② Phrase-prefix multi_match — handles partial / incomplete words.
                            //   "Get" matches "Getting", "Sym" matches "Symfony", etc.
                            //   No fuzziness, but expands the last token as a prefix.
                            //   Boost is lower (0.8) so complete-word fuzzy matches rank higher.
                            [
                                'multi_match' => [
                                    'query'  => $term,
                                    'fields' => ['title^3', 'tags^2', 'content'],
                                    'type'   => 'phrase_prefix',
                                    'boost'  => 0.8,
                                ],
                            ],
                        ],
                        'minimum_should_match' => 1,
                    ],
                ],
                'highlight' => [
                    'pre_tags'  => ['<mark>'],
                    'post_tags' => ['</mark>'],
                    'fields'    => [
                        'title'   => new \stdClass(),
                        'content' => ['fragment_size' => 180, 'number_of_fragments' => 1],
                    ],
                ],
            ]);

            $hits = array_map(function (array $hit): array {
                $src = $hit['_source'] ?? [];
                return [
                    'id'           => $src['id'] ?? null,
                    'title'        => $src['title'] ?? '(untitled)',
                    'slug'         => $src['slug'] ?? '',
                    'tags'         => $src['tags'] ?? [],
                    'published_at' => $src['published_at'] ?? null,
                    'score'        => round((float) ($hit['_score'] ?? 0), 2),
                    'highlights'   => $hit['highlight'] ?? [],
                ];
            }, $response['hits']['hits'] ?? []);

            return [
                'hits'  => $hits,
                'total' => $response['hits']['total']['value'] ?? count($hits),
                'error' => null,
            ];
        } catch (ClientResponseException | ServerResponseException $e) {
            return ['hits' => [], 'total' => 0, 'error' => 'Elasticsearch error: '.$e->getMessage()];
        }
    }
}

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
}

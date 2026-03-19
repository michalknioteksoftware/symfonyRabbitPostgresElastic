<?php

declare(strict_types=1);

namespace App\Factory;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

class ElasticsearchClientFactory
{
    public static function create(string $elasticsearchUrl): Client
    {
        return ClientBuilder::create()
            ->setHosts([$elasticsearchUrl])
            ->build();
    }
}

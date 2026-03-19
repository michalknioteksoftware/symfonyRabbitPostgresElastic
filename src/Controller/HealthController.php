<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ElasticsearchService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health_check', methods: ['GET'])]
    public function check(
        Connection $connection,
        CacheInterface $cache,
        ElasticsearchService $elasticsearch,
    ): JsonResponse {
        $status = [
            'status'   => 'ok',
            'services' => [],
        ];

        // PostgreSQL check
        try {
            $connection->executeQuery('SELECT 1');
            $status['services']['postgresql'] = 'ok';
        } catch (\Throwable $e) {
            $status['services']['postgresql'] = 'error: '.$e->getMessage();
            $status['status'] = 'degraded';
        }

        // Redis check (via the cache adapter)
        try {
            $cache->get('health_check', static function (ItemInterface $item): string {
                $item->expiresAfter(10);

                return 'ok';
            });
            $status['services']['redis'] = 'ok';
        } catch (\Throwable $e) {
            $status['services']['redis'] = 'error: '.$e->getMessage();
            $status['status'] = 'degraded';
        }

        // Elasticsearch check
        try {
            $status['services']['elasticsearch'] = $elasticsearch->ping() ? 'ok' : 'unreachable';
        } catch (\Throwable $e) {
            $status['services']['elasticsearch'] = 'error: '.$e->getMessage();
            $status['status'] = 'degraded';
        }

        // RabbitMQ is checked implicitly by Messenger; expose env DSN host only
        $status['services']['rabbitmq'] = 'configured (check :15672 for management UI)';

        $httpStatus = $status['status'] === 'ok' ? 200 : 503;

        return $this->json($status, $httpStatus);
    }
}

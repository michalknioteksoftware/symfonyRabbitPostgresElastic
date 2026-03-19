<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

class WelcomeController extends AbstractController
{
    #[Route('/', name: 'welcome', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('welcome/index.html.twig', [
            'php_version'     => PHP_VERSION,
            'symfony_version' => Kernel::VERSION,
            'services'        => [
                ['name' => 'PostgreSQL',    'url' => null,                          'label' => 'localhost:5432'],
                ['name' => 'Redis',         'url' => null,                          'label' => 'localhost:6379'],
                ['name' => 'RabbitMQ',      'url' => 'http://localhost:15672',      'label' => 'Management UI'],
                ['name' => 'Elasticsearch', 'url' => 'http://localhost:9200',       'label' => 'REST API'],
            ],
        ]);
    }
}

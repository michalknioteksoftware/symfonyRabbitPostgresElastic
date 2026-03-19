<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ExampleMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ExampleMessageHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExampleMessage $message): void
    {
        $this->logger->info('Handling ExampleMessage', ['content' => $message->content]);

        // Your business logic here
    }
}

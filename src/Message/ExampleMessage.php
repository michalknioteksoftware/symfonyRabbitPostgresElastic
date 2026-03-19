<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Example message dispatched to the async (RabbitMQ) transport.
 * Add your own message classes in this namespace — they are auto-routed.
 */
final class ExampleMessage
{
    public function __construct(
        public readonly string $content,
    ) {
    }
}

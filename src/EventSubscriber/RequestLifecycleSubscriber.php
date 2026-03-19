<?php

declare(strict_types=1);

namespace App\EventSubscriber;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  FEATURE: EVENT SUBSCRIBER                                              │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  Symfony's EventDispatcher lets you hook into the request lifecycle     │
 * │  without touching controllers or middleware.                            │
 * │                                                                         │
 * │  Two ways to listen:                                                    │
 * │  • EventListener  (#[AsEventListener]) — one listener per class        │
 * │  • EventSubscriber (implements EventSubscriberInterface) — multiple     │
 * │    events handled by one class. This is better when the handlers        │
 * │    share state or logic.                                                │
 * │                                                                         │
 * │  Kernel events (fired on every HTTP request):                           │
 * │  KernelEvents::REQUEST   → request received, before routing            │
 * │  KernelEvents::CONTROLLER → controller resolved                         │
 * │  KernelEvents::RESPONSE  → response ready, before it is sent           │
 * │  KernelEvents::EXCEPTION → an exception was thrown                     │
 * │  KernelEvents::TERMINATE → after response is sent (cleanup)            │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Demonstrates three kernel event hooks:
 *  1. REQUEST   → log incoming API calls
 *  2. RESPONSE  → add a custom response header with request timing
 *  3. EXCEPTION → log unhandled exceptions with context
 */
final class RequestLifecycleSubscriber implements EventSubscriberInterface
{
    /** Stores the request start time so we can calculate duration in RESPONSE. */
    private float $startTime = 0.0;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    // ── Registration ───────────────────────────────────────────────────────────
    // getSubscribedEvents() tells the container which events to listen for and
    // which method handles each. The optional integer is the priority
    // (higher = runs earlier; default is 0).
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest', 10],
            KernelEvents::RESPONSE  => ['onResponse', 0],
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    // ── REQUEST ────────────────────────────────────────────────────────────────
    // isMainRequest() is critical: Symfony fires kernel events for every
    // sub-request too (e.g. profiler toolbar, error pages). Without this guard
    // your logic runs multiple times per page load.
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->startTime = microtime(true);

        $request = $event->getRequest();

        // Only log API routes to avoid noise from profiler / assets.
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $this->logger->info('API request received', [
            'method'     => $request->getMethod(),
            'path'       => $request->getPathInfo(),
            'query'      => $request->query->all(),
            'ip'         => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
        ]);
    }

    // ── RESPONSE ───────────────────────────────────────────────────────────────
    // Perfect for adding headers that must be present on every response
    // (CORS, rate-limit headers, server timing, etc.) without touching
    // individual controllers.
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // Add a custom header showing server-side processing time in ms.
        if ($this->startTime > 0) {
            $ms = round((microtime(true) - $this->startTime) * 1000, 2);
            $response->headers->set('X-Response-Time', $ms.'ms');
        }

        // Example: add an API version header to every response.
        $response->headers->set('X-API-Version', '1.0');
    }

    // ── EXCEPTION ──────────────────────────────────────────────────────────────
    // You can also call $event->setResponse() here to return a custom error
    // response instead of Symfony's default error page.
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $exception = $event->getThrowable();

        $this->logger->error('Unhandled exception', [
            'message' => $exception->getMessage(),
            'class'   => $exception::class,
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
            'path'    => $event->getRequest()->getPathInfo(),
        ]);
    }
}

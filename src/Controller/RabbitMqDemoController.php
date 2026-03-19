<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\NotificationMessage;
use App\Message\PermanentlyFailingMessage;
use App\Message\ReportGenerationMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/rabbitmq-demo', name: 'rabbitmq_demo_')]
final class RabbitMqDemoController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('rabbitmq_demo/index.html.twig');
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    /**
     * Dispatches a NotificationMessage that will always succeed.
     * The worker will pick it up, log the work, and ACK it.
     */
    #[Route('/dispatch/notification', name: 'dispatch_notification', methods: ['POST'])]
    public function dispatchNotification(Request $request): Response
    {
        $recipient = $request->request->get('recipient', 'user@example.com');
        $subject   = $request->request->get('subject', 'Hello from Symfony Messenger');
        $body      = $request->request->get('body', 'This message was sent asynchronously via RabbitMQ.');

        $this->bus->dispatch(new NotificationMessage(
            recipient: (string) $recipient,
            subject:   (string) $subject,
            body:      (string) $body,
        ));

        $this->addFlash('success', sprintf(
            'Notification queued for "%s". Check worker logs to see it processed.',
            $recipient,
        ));

        return $this->redirectToRoute('rabbitmq_demo_index');
    }

    // ── Flaky / retry path ────────────────────────────────────────────────────

    /**
     * Dispatches a ReportGenerationMessage.
     * The handler fails on attempts 1 and 2, succeeds on attempt 3.
     * You can watch the retries in: docker compose logs -f worker
     */
    #[Route('/dispatch/report', name: 'dispatch_report', methods: ['POST'])]
    public function dispatchReport(Request $request): Response
    {
        $type = $request->request->get('report_type', 'monthly-summary');
        $uid  = $request->request->getInt('user_id', 1);

        $this->bus->dispatch(new ReportGenerationMessage(
            reportType:  (string) $type,
            requestedBy: $uid,
        ));

        $this->addFlash('warning', sprintf(
            'Report "%s" queued. It will fail twice and succeed on attempt 3. Watch: docker compose logs -f worker',
            $type,
        ));

        return $this->redirectToRoute('rabbitmq_demo_index');
    }

    // ── Dead-letter path ──────────────────────────────────────────────────────

    /**
     * Dispatches a PermanentlyFailingMessage.
     * The handler immediately throws UnrecoverableMessageHandlingException,
     * skipping all retries and landing in the `messages_failed` queue.
     * Inspect it with: docker compose exec app php bin/console messenger:failed:show
     */
    #[Route('/dispatch/failing', name: 'dispatch_failing', methods: ['POST'])]
    public function dispatchFailing(Request $request): Response
    {
        $reason = $request->request->get('reason', 'Simulated unrecoverable error');

        $this->bus->dispatch(new PermanentlyFailingMessage(
            reason: (string) $reason,
        ));

        $this->addFlash('error', sprintf(
            'Failing message queued with reason: "%s". It will land in the dead-letter queue. '
            . 'Check: docker compose exec app php bin/console messenger:failed:show',
            $reason,
        ));

        return $this->redirectToRoute('rabbitmq_demo_index');
    }

    // ── Bulk dispatch ─────────────────────────────────────────────────────────

    /**
     * Dispatches multiple messages of all three types at once so you can watch
     * the queue fill up and drain in the RabbitMQ Management UI.
     */
    #[Route('/dispatch/bulk', name: 'dispatch_bulk', methods: ['POST'])]
    public function dispatchBulk(Request $request): Response
    {
        $count = max(1, min(20, $request->request->getInt('count', 5)));

        for ($i = 1; $i <= $count; $i++) {
            $this->bus->dispatch(new NotificationMessage(
                recipient: "user{$i}@example.com",
                subject:   "Bulk notification #{$i}",
                body:      "This is bulk message #{$i} dispatched at " . date('H:i:s'),
            ));
        }

        $this->bus->dispatch(new ReportGenerationMessage(
            reportType:  'bulk-test-report',
            requestedBy: 1,
        ));

        $this->bus->dispatch(new PermanentlyFailingMessage(
            reason: 'Bulk dispatch — intentional dead-letter demo',
        ));

        $this->addFlash('success', sprintf(
            'Bulk dispatch: %d notification(s) + 1 flaky report + 1 failing message sent. '
            . 'Open RabbitMQ UI to watch the queue.',
            $count,
        ));

        return $this->redirectToRoute('rabbitmq_demo_index');
    }
}

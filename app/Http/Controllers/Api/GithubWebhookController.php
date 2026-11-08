<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubRepositoryLink;
use App\Models\GithubWebhookEvent;
use App\Models\User;
use App\Services\GithubService;
use App\Services\TelegramService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GithubWebhookController extends Controller
{
    public function __construct(
        protected GithubService $githubService,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Handle incoming GitHub webhook delivery.
     */
    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header('X-Hub-Signature-256');
        $deliveryId = $request->header('X-GitHub-Delivery');
        $event = $request->header('X-GitHub-Event');

        if (! $deliveryId || ! $event) {
            return response()->json([
                'message' => 'Missing GitHub webhook headers (X-GitHub-Delivery or X-GitHub-Event).',
            ], 400);
        }

        // Raw body MUST be used for HMAC verification
        $rawPayload = $request->getContent();

        // Timing-safe HMAC-SHA256 signature verification
        if (! $this->githubService->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Rejected GitHub webhook due to invalid signature', [
                'delivery_id' => $deliveryId,
                'event' => $event,
            ]);

            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        // Validate event is supported
        $supportedEvents = ['push', 'pull_request', 'issues', 'installation', 'ping'];
        if (! in_array($event, $supportedEvents, true)) {
            // Acknowledge receipt of unsupported event gracefully
            return response()->json([
                'message' => "Event '{$event}' ignored (unsupported).",
            ], 200);
        }

        $payload = $request->json()->all();
        $action = $payload['action'] ?? null;

        try {
            // Process idempotently
            $webhookEvent = $this->githubService->processWebhookEvent(
                $deliveryId,
                $event,
                $payload,
                $action
            );

            // Updates are idempotent: duplicate deliveries only rewrite the
            // same linked records.
            $linkedUpdates = $this->githubService->applyWebhookToLinks($event, $payload);

            try {
                $this->notifyLinkedRepository($event, $payload, $action);
            } catch (Exception $exception) {
                Log::warning('GitHub webhook notification could not be sent.', [
                    'delivery_id' => $deliveryId,
                    'error' => $exception->getMessage(),
                ]);
            }

            return response()->json([
                'status' => 'success',
                'delivery_id' => $deliveryId,
                'event' => $event,
                'action' => $action,
                'linked_records_updated' => $linkedUpdates,
                'message' => 'Webhook received and processed successfully.',
            ], 200);
        } catch (Exception $e) {
            Log::error('Error processing GitHub webhook', [
                'delivery_id' => $deliveryId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to process webhook event.',
            ], 500);
        }
    }

    /**
     * Notify owners of linked repositories about push, pull request and issue activity.
     *
     * @param  array<string, mixed>  $payload
     */
    private function notifyLinkedRepository(string $event, array $payload, ?string $action): void
    {
        if (! in_array($event, ['push', 'pull_request', 'issues'], true)) {
            return;
        }

        $fullName = $payload['repository']['full_name'] ?? null;

        if (! is_string($fullName) || $fullName === '') {
            return;
        }

        $links = GithubRepositoryLink::query()
            ->where('full_name', $fullName)
            ->with(['user', 'project'])
            ->get();

        $recipients = $links
            ->pluck('user')
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $sender = $payload['sender']['login'] ?? null;
        $message = null;

        if ($event === 'push') {
            $ref = (string) ($payload['ref'] ?? '');
            $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : $ref;
            $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];

            $message = $this->telegram->message('🚀 Code pushed', [
                'Repository' => $fullName,
                'Branch' => $branch,
                'Commits' => count($commits),
                'By' => $sender,
                'Compare' => is_string($payload['compare'] ?? null) ? $payload['compare'] : null,
            ]);
        }

        if ($event === 'pull_request' && is_array($payload['pull_request'] ?? null)) {
            $pullRequest = $payload['pull_request'];

            $message = $this->telegram->message('🔀 Pull request '.($action ?? 'updated'), [
                'Repository' => $fullName,
                'Pull request' => '#'.($pullRequest['number'] ?? '?').' '.($pullRequest['title'] ?? ''),
                'By' => $pullRequest['user']['login'] ?? $sender,
                'URL' => $pullRequest['html_url'] ?? null,
            ]);
        }

        if ($event === 'issues' && is_array($payload['issue'] ?? null)) {
            $issue = $payload['issue'];

            $message = $this->telegram->message('🐞 Issue '.($action ?? 'updated'), [
                'Repository' => $fullName,
                'Issue' => '#'.($issue['number'] ?? '?').' '.($issue['title'] ?? ''),
                'By' => $issue['user']['login'] ?? $sender,
                'URL' => $issue['html_url'] ?? null,
            ]);
        }

        if ($message === null) {
            return;
        }

        $this->telegram->sendToUsers($recipients, TelegramService::EVENT_GITHUB_EVENT, $message, [
            'workspace_id' => $links->first()?->project?->workspace_id,
            'payload' => [
                'event' => $event,
                'action' => $action,
                'repository' => $fullName,
            ],
        ]);
    }

    /**
     * List recent verified webhook events for the frontend stream.
     */
    public function events(Request $request): JsonResponse
    {
        $limit = min(50, max(5, (int) $request->query('limit', 20)));
        $repositoryNames = $request->user()->githubRepositoryLinks()->pluck('full_name');

        if ($repositoryNames->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $events = GithubWebhookEvent::query()
            ->whereIn('repository', $repositoryNames)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (GithubWebhookEvent $event) {
                return [
                    'id' => $event->delivery_id,
                    'event' => $event->event.($event->action ? ".{$event->action}" : ''),
                    'raw_event' => $event->event,
                    'action' => $event->action,
                    'repo' => $event->repository ?? 'General / Account',
                    'sender' => $event->sender ?? 'github',
                    'status' => $event->processed ? '200 OK' : 'Pending',
                    'created_at' => $event->created_at->toIso8601String(),
                    'time_ago' => $event->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'data' => $events,
        ]);
    }
}

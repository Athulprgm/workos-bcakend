<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleSheetsService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleOAuthController extends Controller
{
    public function __construct(protected GoogleSheetsService $service) {}

    public function connect(Request $request): JsonResponse
    {
        $url = $this->service->getAuthorizationUrl($request->user());

        return response()->json(['url' => $url]);
    }

    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        $frontendUrl = config('services.google.frontend_url', config('services.github.frontend_url', 'http://localhost:5173'));
        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            $message = $request->query('error_description') ?? 'Google authorization was cancelled or incomplete.';

            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 400);
            }

            return redirect($frontendUrl.'/google-sheets?error='.urlencode($message));
        }

        try {
            $account = $this->service->handleCallback($code, $state);

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Google account connected successfully.',
                    'account' => ['email' => $account->email],
                ]);
            }

            return redirect($frontendUrl.'/google-sheets?status=connected&email='.urlencode($account->email));
        } catch (Exception $e) {
            Log::warning('Google OAuth callback failed', ['error' => $e->getMessage()]);

            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect($frontendUrl.'/google-sheets?error='.urlencode($e->getMessage()));
        }
    }

    public function status(Request $request): JsonResponse
    {
        $account = $request->user()->googleAccount;

        return response()->json([
            'configured' => ! empty(config('services.google.client_id')) && ! empty(config('services.google.client_secret')),
            'connected' => (bool) $account,
            'account' => $account ? [
                'email' => $account->email,
                'scopes' => $account->scopes ?? [],
                'connected_at' => $account->created_at?->toIso8601String(),
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->service->disconnect($request->user());

        return response()->json([
            'message' => 'Google account disconnected. Sheet connections were removed.',
            'connected' => false,
        ]);
    }
}

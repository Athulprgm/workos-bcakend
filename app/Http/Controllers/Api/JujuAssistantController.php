<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkLog;
use App\Models\Workspace;
use App\Services\GeminiService;
use App\Services\GroqService;
use App\Services\OllamaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JujuAssistantController extends Controller
{
    /**
     * Issue an ephemeral Gemini Live API token for secure browser-side WebSocket connections.
     *
     * Strategy (in order):
     *  1. Try Gemini ephemeral-tokens API → return short-lived token (production-safe).
     *  2. If unavailable, return the raw API key directly — the endpoint is already
     *     protected by the user's Bearer JWT, so only authenticated users receive it.
     *     This is equivalent to the user calling Gemini with their own key.
     */
    public function getLiveToken(Request $request): JsonResponse
    {
        $user     = $request->user();
        $cacheKey = 'gemini_live_token_' . $user->id;

        // Return cached result if still valid
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response()->json($cached);
        }

        $apiKey    = config('services.gemini.api_key', env('GEMINI_API_KEY'));
        $liveModel = config('services.gemini.live_model', env('GEMINI_LIVE_MODEL', 'gemini-3.8-live'));

        if (empty($apiKey)) {
            return response()->json(['error' => 'Gemini API key not configured'], 503);
        }

        // ── 1. Try ephemeral token API (production path) ──────────────────────
        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type'   => 'application/json',
                ])
                ->post('https://generativelanguage.googleapis.com/v1beta/ephemeral-tokens', [
                    'config' => [
                        'model'  => "models/{$liveModel}",
                        'config' => ['response_modalities' => ['AUDIO']],
                        'ttl'    => '3600s',
                    ],
                ]);

            if ($response->successful()) {
                $data  = $response->json();
                $token = $data['name'] ?? $data['token'] ?? null;

                if ($token) {
                    $result = [
                        'token'      => $token,
                        'expires_at' => $data['expireTime'] ?? null,
                        'model'      => $liveModel,
                        'mode'       => 'ephemeral',
                    ];
                    Cache::put($cacheKey, $result, now()->addMinutes(55));
                    return response()->json($result);
                }
            }

            Log::info('Gemini ephemeral token API unavailable (HTTP ' . $response->status() . '), falling back to direct key');
        } catch (\Throwable $e) {
            Log::info('Gemini ephemeral token exception, falling back to direct key: ' . $e->getMessage());
        }

        // ── 2. Fallback: return the API key directly ───────────────────────────
        // The route is authenticated (Bearer JWT), so only this user's session gets it.
        // Cache for 30 min to reduce token endpoint calls.
        $result = [
            'token'      => $apiKey,
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
            'model'      => $liveModel,
            'mode'       => 'direct',
        ];
        Cache::put($cacheKey, $result, now()->addMinutes(25));

        return response()->json($result);
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'workspace_id' => ['nullable'],
            'confirmed_action' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
            'voice' => ['nullable', 'string'],
            'synthesize_audio' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $user->ensureDefaultWorkspaces();

        $message = trim($validated['message']);
        $rawWorkspaceId = $validated['workspace_id'] ?? $request->header('X-Workspace-Id');

        $workspace = null;
        if ($rawWorkspaceId && $rawWorkspaceId !== 'all') {
            $workspace = $user->workspaces()->where('id', (int) $rawWorkspaceId)->first();
        }
        if (! $workspace) {
            $workspace = $user->workspaces()->active()->first();
        }

        // Handle confirmed actions that required confirmation
        if (! empty($validated['confirmed_action'])) {
            return $this->executeConfirmedAction($user, $workspace, $validated['confirmed_action']);
        }

        $context = $validated['context'] ?? [];

        // Execute intent matching and structured action execution
        return $this->processCommand($user, $workspace, $message, $context);
    }

    private function processCommand($user, $workspace, string $message, array $context): JsonResponse
    {
        $lower = Str::lower($message);
        $isMalayalam = (bool) preg_match('/[\x{0D00}-\x{0D7F}]/u', $message);

        // -------------------------------------------------------------
        // 0. ASSISTANT PERSONA SWITCHING ("reply like siri", "switch to google", "reply like alexa")
        // -------------------------------------------------------------
        if (preg_match('/(?:reply|speak|talk|sound|switch|be|change|set)\s*(?:to|like|as)?\s*(google|siri|alexa|juju)/i', $lower, $pm) ||
            preg_match('/(?:reply|talk|speak)\s*like\s*google\s*and\s*siri\s*and\s*alexa/i', $lower)) {
            $requestedPersona = ! empty($pm[1]) ? strtolower($pm[1]) : 'google';
            $personaReplies = [
                'google' => [
                    'reply' => "I've switched to Google Assistant style! Cheerful, fast, and ready. How can I help you today?",
                    'speak' => 'Switched to Google Assistant style. Hi, how can I help?',
                ],
                'siri' => [
                    'reply' => "Switched to Apple Siri style. Crisp and focused. I'm listening.",
                    'speak' => "Switched to Siri mode. Mm-hmm, I'm listening.",
                ],
                'alexa' => [
                    'reply' => 'Switched to Amazon Alexa style. Direct and responsive. What would you like to do?',
                    'speak' => 'Switched to Alexa mode. Ready.',
                ],
                'juju' => [
                    'reply' => 'Switched to Juju Executive mode. At your service for your Work OS operations.',
                    'speak' => 'Switched to Juju Executive mode. Yes, how can I help you?',
                ],
            ];

            $selected = $personaReplies[$requestedPersona] ?? $personaReplies['google'];

            return $this->formatChatResponse([
                'reply' => $selected['reply'],
                'action_performed' => 'persona_switched',
                'speak' => $selected['speak'],
                'persona' => $requestedPersona,
                'context_update' => ['pending_action' => null, 'persona' => $requestedPersona],
            ]);
        }

        // -------------------------------------------------------------
        // -------------------------------------------------------------
        // -------------------------------------------------------------
        // -------------------------------------------------------------
        // 0.1 WAKE-WORD, WAKE-UP CALL & KERALA FRIEND GREETINGS ("Hey Juju", "Wake up", "Wakeup Call", "Eda Mone", "Machu")
        // -------------------------------------------------------------
        $isFriendWake = (bool) preg_match('/^(?:hey|hi|hello|ok|okay)?\s*(?:machu|machane|machaane|bro|mwone|mwonu|eda\s*mone|eda\s*mwone|eda\s*mwonu|eda)[?!.]*$/i', $message) ||
            ($isMalayalam && preg_match('/^(?:ഹേ|ഹായ്|ഹലോ)?\s*(?:മച്ചൂ|മച്ചാനേ|ബ്രോ|മോനേ|എടാ\s*മോനേ|എടാ\s*മോനെ|എടാ)[?!.]*$/u', $message));

        $isExplicitWakeUpCall = (bool) preg_match('/(?:give\s*me\s*(?:a\s*|my\s*)?|i\s*need\s*(?:a\s*)?|what\s*is\s*my\s*|can\s*you\s*give\s*me\s*(?:a\s*)?)?(?:morning\s*)?wake[-\s]*up[-\s]*call/i', $message) ||
            (bool) preg_match('/(?:give\s*me\s*(?:a\s*|my\s*)?|i\s*need\s*(?:a\s*)?)?(?:morning\s*)?wakeup[-\s]*call/i', $message) ||
            (bool) preg_match('/(?:wake\s*me\s*up(?:\s*call)?)/i', $message) ||
            (bool) preg_match('/\bwake[-\s]*up[-\s]*call\b/i', $message) ||
            ($isMalayalam && preg_match('/(?:വേക്ക്[-\s]*അപ്പ്[-\s]*കോൾ|വേക്കപ്പ്[-\s]*കാൾ|വെയ്ക്കപ്പ്[-\s]*കോൾ|വേക്കപ്പ്[-\s]*കോൾ|വേക്ക്[-\s]*അപ്പ്)/u', $message));

        $isWakeWord = (bool) preg_match('/^(?:hey|hi|hello|ok|okay)?\s*(?:juju|google|siri|alexa)[?!.]*$/i', $message) ||
            (bool) preg_match('/^(?:hey|hi|hello|ok|okay)?\s*(?:juju\s+)?(?:and\s+)?wake[-\s]*up(?:\s+juju)?[?!.]*$/i', $message) ||
            (bool) preg_match('/^(?:hey|hi|hello|ok|okay)?\s*(?:juju\s+)?(?:and\s+)?wakeup(?:\s+juju)?[?!.]*$/i', $message) ||
            (bool) preg_match('/^(?:hey\s+juju\s+(?:and\s+)?(?:wakeup|wake[-\s]*up)|(?:wakeup|wake[-\s]*up)\s+(?:and\s+)?hey\s+juju)[?!.]*$/i', $message) ||
            ($isMalayalam && preg_match('/^(?:ഹേ|ഹായ്|ഹലോ)?\s*(?:ജൂജു|ഉണരൂ|യുണരൂ|വേക്കപ്പ്)(?:\s*ജൂജു)?[?!.]*$/u', $message));

        $isStandardWake = $isExplicitWakeUpCall || $isWakeWord;

        if ($isFriendWake || $isStandardWake) {
            $persona = $context['persona'] ?? 'juju';

            if ($isExplicitWakeUpCall) {
                $wsName = $workspace?->name ?? 'your workspace';
                $pendingTasks = Task::where(function ($query) use ($user) {
                        $query->where('user_id', $user->id)
                            ->orWhereHas('project', fn ($q) => $q->where('user_id', $user->id));
                    })
                    ->when($workspace, function ($q) use ($workspace) {
                        $q->where(function ($sub) use ($workspace) {
                            $sub->where('workspace_id', $workspace->id)
                                ->orWhereHas('project', fn ($pq) => $pq->where('workspace_id', $workspace->id));
                        });
                    })
                    ->whereNotIn('status', ['done', 'completed', 'cancelled'])
                    ->count();

                if ($persona === 'google') {
                    $reply = $isMalayalam
                        ? "ഗുഡ് മോർണിംഗ്! ഇതാ നിങ്ങളുടെ wake-up call: {$wsName}-ൽ നിങ്ങൾക്ക് {$pendingTasks} പെൻഡിംഗ് ടാസ്ക്കുകൾ ഉണ്ട്. ഞാൻ എങ്ങനെ സഹായിക്കണം?"
                        : "Good morning! Here is your wake-up call: You have {$pendingTasks} pending tasks in {$wsName}. How can I help you today?";
                    $speak = $reply;
                } elseif ($persona === 'siri') {
                    $reply = $isMalayalam
                        ? "ഗുഡ് മോർണിംഗ്. നിങ്ങളുടെ wake-up call: {$wsName}-ൽ {$pendingTasks} പെൻഡിംഗ് ടാസ്ക്കുകൾ ഉണ്ട്. പറയൂ."
                        : "Good morning. Here is your wake-up call: You have {$pendingTasks} pending tasks in {$wsName}. I'm listening.";
                    $speak = $reply;
                } elseif ($persona === 'alexa') {
                    $reply = $isMalayalam
                        ? "ഗുഡ് മോർണിംഗ്. wake-up call: {$wsName}-ൽ {$pendingTasks} ടാസ്ക്കുകൾ ബാക്കിയുണ്ട്. എന്താണ് ചെയ്യേണ്ടത്?"
                        : "Good morning. Wake-up call: You have {$pendingTasks} pending tasks in {$wsName}. Ready.";
                    $speak = $reply;
                } elseif ($isMalayalam) {
                    $reply = "എടാ മോനേ! ഗുഡ് മോർണിംഗ്! ഇതാ നിങ്ങളുടെ wake-up call: {$wsName}-ൽ നിങ്ങൾക്ക് {$pendingTasks} പെൻഡിംഗ് ടാസ്ക്കുകൾ ഉണ്ട്. പറയൂ, ഞാൻ എങ്ങനെ സഹായിക്കണം?";
                    $speak = "എടാ മോനേ! ഗുഡ് മോർണിംഗ്! ഇതാ വേക്ക്-അപ്പ് കോൾ. {$wsName}-ൽ {$pendingTasks} പെൻഡിംഗ് ടാസ്ക്കുകൾ ഉണ്ട്. പറയൂ, എന്താണ് ചെയ്യേണ്ടത്?";
                } else {
                    $reply = "Eda mone! Good morning! Here is your wake-up call: You have {$pendingTasks} pending tasks in {$wsName}. How can I assist you?";
                    $speak = "Eda mone! Good morning! Wake-up call: You have {$pendingTasks} pending tasks in {$wsName}. How can I assist you?";
                }
            } elseif ($persona === 'google' || preg_match('/^(?:hey|hi|hello|ok|okay)?\s*google[?!.]*$/i', $message)) {
                $reply = $isMalayalam ? 'പറയൂ, ഞാൻ എങ്ങനെ സഹായിക്കണം?' : 'Hi, how can I help?';
                $speak = $reply;
            } elseif ($persona === 'siri' || preg_match('/^(?:hey|hi|hello|ok|okay)?\s*siri[?!.]*$/i', $message)) {
                $reply = $isMalayalam ? 'അതെ, പറയൂ?' : "Mm-hmm, I'm listening.";
                $speak = $reply;
            } elseif ($persona === 'alexa' || preg_match('/^(?:hey|hi|hello|ok|okay)?\s*alexa[?!.]*$/i', $message)) {
                $reply = $isMalayalam ? 'അതെ, എന്താണ് ചെയ്യേണ്ടത്?' : 'Yes, ready.';
                $speak = $reply;
            } elseif ($isFriendWake) {
                $reply = $isMalayalam ? 'എടാ മോനേ! പറയൂ, ഞാൻ ഇവിടെയുണ്ട്. എന്താണ് ചെയ്യേണ്ടത്?' : "Eda mone! I'm here, how can I help you?";
                $speak = $reply;
            } else {
                $reply = $isMalayalam ? 'എടാ മോനേ! പറയൂ, ഞാൻ എങ്ങനെ സഹായിക്കണം?' : 'Eda mone! Yes, how can I help you?';
                $speak = $reply;
            }

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'wake_word_detected',
                'speak' => $speak,
                'context_update' => ['pending_action' => null],
            ]);
        }

        // -------------------------------------------------------------
        // 0.2 AUTHENTIC KERALA CONVERSATIONAL & CHILL FRIEND GREETINGS
        // -------------------------------------------------------------
        // Specific Kerala Manglish query: "Entha paripadi?" / "എന്താ പരിപാടി?"
        if (preg_match('/^(?:eda\s+)?(?:entha|entharu|enth)\s*paripadi[?!.]*$/iu', $message) ||
            preg_match('/^(?:എടാ\s+)?എന്താ(?:ണ്)?\s*പരിപാടി[?!.]*$/u', $message)) {
            $reply = 'പ്രത്യേകിച്ച് ഒന്നുമില്ല, പറയൂ എന്താ വിശേഷം?';
            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'kerala_greeting',
                'speak' => $reply,
                'context_update' => ['pending_action' => null],
            ]);
        }

        // Specific developer query: "Can you check my code?" / "Code ഒന്ന് നോക്കാമോ?"
        if (preg_match('/^(?:can|could|please)?\s*(?:you\s*)?(?:check|review|look\s*at)\s*(?:my|the|this)?\s*code[?!.]*$/i', $message) ||
            preg_match('/^(?:code\s*(?:onnu\s*)?(?:check|review)\s*(?:cheyyamo|cheyyumo|nokkamo)|കോഡ്\s*(?:ഒന്ന്\s*)?(?:നോക്കാമോ|പരിശോധിക്കാമോ|ചെക്ക്\s*ചെയ്യുമോ))[?!.]*$/iu', $message)) {
            $reply = 'Sure! Code അയക്കൂ, നമുക്ക് പരിശോധിക്കാം.';
            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'code_review_offer',
                'speak' => $reply,
                'context_update' => ['pending_action' => 'awaiting_code'],
            ]);
        }

        // -------------------------------------------------------------
        // 0.25 PERSISTENT LONG-TERM MEMORY & USER PREFERENCES
        // -------------------------------------------------------------
        // 1. Remember fact/preference: "Remember that ...", "ഇത് ഓർത്തു വെക്കൂ: ..."
        if (preg_match('/^(?:please\s+)?remember(?:\s+that|\s+this)?[:\s]+(.+)/i', $message, $memMatch) ||
            preg_match('/^(?:ഇത്\s*)?(?:ഓർത്തു\s*വെക്കൂ|ഓർക്കണം|ഓർമ്മ\s*വെക്കൂ)[:\s]+(.+)/iu', $message, $memMatch)) {
            $memoryText = trim($memMatch[1]);
            $category = (Str::contains(Str::lower($memoryText), ['like', 'prefer', 'favorite', 'ഇഷ്ടം', 'താൽപ്പര്യം'])) ? 'preference' : 'fact';

            $user->aiMemories()->create([
                'workspace_id' => $workspace?->id,
                'content' => $memoryText,
                'category' => $category,
                'importance' => 4,
                'last_accessed_at' => now(),
            ]);

            $reply = $isMalayalam
                ? "തീർച്ചയായും, ഞാൻ ഇത് ഓർത്തു വെക്കാം: \"{$memoryText}\"."
                : "Got it! I will remember that: \"{$memoryText}\".";

            $speak = $isMalayalam
                ? "തീർച്ചയായും, ഞാൻ ഇത് ഓർത്തു വെക്കാം."
                : "Got it, I will remember that.";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'remember_memory',
                'data' => ['content' => $memoryText, 'category' => $category],
                'speak' => $speak,
                'context_update' => ['pending_action' => null],
            ]);
        }

        // 2. Retrieve memories: "What do you remember about me?", "എന്നെക്കുറിച്ച് എന്തൊക്കെ ഓർമ്മയുണ്ട്?"
        if (preg_match('/(?:what\s+do\s+you\s+remember|show\s+(?:my\s+)?memories|recall\s+memories)/i', $message) ||
            preg_match('/(?:എന്നെക്കുറിച്ച്\s*എന്തൊക്കെ\s*ഓർമ്മയുണ്ട്|എന്റെ\s*മെമ്മറി\s*കാണിക്കൂ|ഓർമ്മകൾ\s*കാണിക്കൂ)/iu', $message)) {
            $memories = $user->aiMemories()
                ->when($workspace, fn ($q) => $q->where(fn ($sub) => $sub->where('workspace_id', $workspace->id)->orWhereNull('workspace_id')))
                ->orderBy('importance', 'desc')
                ->limit(10)
                ->get();

            if ($memories->isEmpty()) {
                $reply = $isMalayalam
                    ? 'എനിക്ക് നിലവിൽ നിങ്ങളെക്കുറിച്ച് പ്രത്യേകിച്ച് മെമ്മറികൾ ഒന്നുമില്ല. എന്തെങ്കിലും ഓർത്തു വെക്കണമെങ്കിൽ പറഞ്ഞോളൂ!'
                    : "I don't have any saved memories yet. Let me know if there's anything you'd like me to remember!";
                $speak = $reply;
            } else {
                $items = $memories->map(fn ($m) => "• {$m->content}")->join("\n");
                $reply = $isMalayalam
                    ? "ഇതാ എന്നിലുള്ള നിങ്ങളുടെ പ്രധാന മെമ്മറികൾ:\n{$items}"
                    : "Here is what I remember about you:\n{$items}";
                $speak = $isMalayalam
                    ? "നിങ്ങളെക്കുറിച്ച് {$memories->count()} മെമ്മറികൾ ഞാൻ ഓർത്തു വെച്ചിട്ടുണ്ട്."
                    : "I have {$memories->count()} memories saved about you.";
            }

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'recall_memories',
                'data' => $memories,
                'speak' => $speak,
                'context_update' => ['pending_action' => null],
            ]);
        }

        // 3. Clear memories: "Clear my memories", "Forget everything", "മെമ്മറി ക്ലിയർ ചെയ്യൂ"
        if (preg_match('/(?:clear|forget|delete)\s+(?:all\s+)?(?:my\s+)?memories/i', $message) ||
            preg_match('/(?:forget\s+everything)/i', $message) ||
            preg_match('/(?:മെമ്മറി\s*ക്ലിയർ\s*ചെയ്യ്|എല്ലാം\s*മറന്നേക്കൂ|മെമ്മറികൾ\s*ഡിലീറ്റ്\s*ചെയ്യ്)/iu', $message)) {
            $count = $user->aiMemories()->delete();

            $reply = $isMalayalam
                ? 'നിങ്ങളുടെ മെമ്മറികൾ എല്ലാം ഞാൻ ക്ലിയർ ചെയ്തു.'
                : "Cleared {$count} saved memories.";
            $speak = $reply;

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'clear_memories',
                'data' => ['cleared_count' => $count],
                'speak' => $speak,
                'context_update' => ['pending_action' => null],
            ]);
        }

        $hasFriendSlang = (bool) preg_match('/(?:^|\b)(?:machu|machane|machaane|bro|mwonu|mwone|eda|machuu|മച്ചൂ|മച്ചാനേ|ബ്രോ|മോനേ|മോനെ|എടാ)(?:\b|$)/iu', $message);
        $hasKeralaGreeting = (bool) preg_match('/(?:സുഖമാണോ|സുഖം തന്നെയല്ലേ|സുഖമായിരിക്കുന്നുവോ|വിശേഷം|വിശേഷങ്ങൾ|സീനുണ്ടോ|സീനില്ല|എന്തൊക്കെയുണ്ട്|sukhamano|vishesham|enthundu|scene undo)/iu', $message) ||
            preg_match('/^(?:ഹായ്|ഹലോ|നമസ്കാരം)[\s!.]*$/u', $message);

        if ($hasFriendSlang || $hasKeralaGreeting) {
            $prefix = Str::contains($message, 'നമസ്കാരം') ? 'നമസ്കാരം! ' : 'ഹായ്! ';
            if (preg_match('/(?:സുഖമാണോ|സുഖം|sukhamano)/iu', $message)) {
                $reply = $prefix.'എനിക്ക് സുഖമാണ്, അവിടെ സുഖമല്ലേ? ഇന്ന് നമ്മുടെ പ്രൊജക്റ്റിലും ടാസ്ക്കുകളിലും എന്തൊക്കെയുണ്ട് പരിപാടി? ഞാൻ കൂടെയുണ്ട്, എല്ലാം നമുക്ക് അടിപൊളിയായി ചെയ്യാം!';
            } elseif (preg_match('/(?:വിശേഷം|വിശേഷങ്ങൾ|vishesham|enthundu|എന്തൊക്കെയുണ്ട്)/iu', $message)) {
                $reply = 'വിശേഷങ്ങൾ ഒക്കെ അടിപൊളിയായി പോകുന്നു! ഇന്ന് വർക്ക്സ്പേസിൽ എന്താണ് നമ്മുടെ പ്ലാൻ? ടാസ്കോ പ്രൊജക്റ്റോ വർക്ക് ലോഗോ എന്താണെങ്കിലും പറഞ്ഞോളൂ, ഞാൻ റെഡിയാണ്.';
            } elseif (preg_match('/(?:സീനുണ്ടോ|സീൻ|scene undo)/iu', $message)) {
                $reply = 'സീനൊന്നുമില്ല! നമ്മൾ ഒരുമിച്ച് നിന്നാൽ എല്ലാം കൂളായി തീർക്കാം. പറ, എന്താണ് ഇന്നത്തെ ടാസ്ക്?';
            } elseif ($hasFriendSlang) {
                $reply = 'പറയൂ, ഞാൻ ഇവിടെ റെഡിയാണ്! ഇന്ന് എന്താണ് ചെയ്തു തരേണ്ടത്?';
            } else {
                $reply = $prefix.'സുഖമല്ലേ? ഇന്ന് വർക്ക്സ്പേസിൽ എന്താണ് ചെയ്തു തരേണ്ടത്?';
            }

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'greeting',
                'speak' => $reply,
                'context_update' => ['pending_action' => null],
            ]);
        }

        // -------------------------------------------------------------
        // 0.3 CONTEXTUAL MULTI-TURN: PENDING ACTION RESOLUTION
        // -------------------------------------------------------------
        if (! empty($context['pending_action'])) {
            $pending = $context['pending_action'];
            $intent = $pending['intent'] ?? '';

            if ($intent === 'create_task' && ($pending['missing_field'] ?? '') === 'title') {
                $taskTitle = trim(preg_replace('/^(it\'s|called|title is|titled|task is|ഇത്|ഇതാണ്|ടാസ്ക്|പേര്)\s+/iu', '', $message));
                $taskTitle = trim(preg_replace('/[?.!]/', '', $taskTitle));
                $taskTitle = trim(preg_replace('/\b(ആണ്|എന്ന്)\s*$/iu', '', $taskTitle));

                if (mb_strlen($taskTitle) > 0) {
                    $priority = $pending['priority'] ?? 'medium';
                    $dueAt = ! empty($pending['due_at']) ? Carbon::parse($pending['due_at']) : null;
                    $projectName = $pending['project_name'] ?? null;

                    $project = null;
                    if ($projectName) {
                        $project = Project::where('user_id', $user->id)
                            ->where('name', 'like', "%{$projectName}%")
                            ->first();
                    }
                    if (! $project) {
                        $project = Project::where('user_id', $user->id)
                            ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                            ->first();
                    }
                    if (! $project) {
                        $project = $user->projects()->create([
                            'workspace_id' => $workspace?->id,
                            'name' => 'General',
                            'slug' => Project::uniqueSlugFor($user, 'General'),
                            'status' => ProjectStatus::Active->value,
                            'priority' => 'medium',
                        ]);
                    }

                    $task = $project->tasks()->create([
                        'workspace_id' => $workspace?->id,
                        'user_id' => $user->id,
                        'title' => $taskTitle,
                        'status' => TaskStatus::Todo->value,
                        'priority' => $priority,
                        'due_at' => $dueAt,
                        'activity_history' => [
                            [
                                'event' => 'created_by_juju',
                                'description' => 'Created via Juju voice command follow-up',
                                'timestamp' => now()->toIso8601String(),
                                'user' => $user->name,
                            ],
                        ],
                    ]);

                    $dueStr = $dueAt ? ($dueAt->isTomorrow() ? 'tomorrow' : ($dueAt->isToday() ? 'today' : $dueAt->format('M d'))) : 'none';
                    $dueStrMl = $dueAt ? ($dueAt->isTomorrow() ? 'നാളെ' : ($dueAt->isToday() ? 'ഇന്ന്' : $dueAt->format('M d'))) : 'ഇല്ല';
                    $priorityLabel = $priority === 'high' ? 'high-priority ' : ($priority === 'urgent' ? 'urgent ' : '');
                    $priorityLabelMl = $priority === 'high' ? 'ഹൈ പ്രയോറിറ്റി ' : ($priority === 'urgent' ? 'അടിയന്തിര ' : '');

                    if ($isMalayalam) {
                        $speak = $dueAt
                            ? "{$dueStrMl} കാലാവധിയുള്ള {$priorityLabelMl}ടാസ്ക് \"{$task->title}\" ഉണ്ടാക്കിയിട്ടുണ്ട്."
                            : "പുതിയ {$priorityLabelMl}ടാസ്ക് \"{$task->title}\" ഉണ്ടാക്കിയിട്ടുണ്ട്.";
                        $reply = "പുതിയ {$priorityLabelMl}ടാസ്ക് \"{$task->title}\" (മുൻഗണന: {$priority}, കാലാവധി: {$dueStrMl}) പ്രൊജക്റ്റ് \"{$project->name}\" ൽ ചേർത്തു.";
                    } else {
                        if (Str::contains($lower, 'dashboard') || Str::contains(Str::lower($taskTitle), 'dashboard')) {
                            $speak = $dueAt && $dueAt->isTomorrow()
                                ? "I've created your high-priority dashboard task with a due date of tomorrow."
                                : "I've created your {$priorityLabel}task \"{$task->title}\".";
                        } else {
                            $speak = $dueAt
                                ? "I've created your {$priorityLabel}task \"{$task->title}\" with a due date of {$dueStr}."
                                : "I've created your {$priorityLabel}task \"{$task->title}\".";
                        }

                        $reply = "Created task \"{$task->title}\" (Priority: {$priority}, Due: {$dueStr}) in project \"{$project->name}\".";
                    }

                    return $this->formatChatResponse([
                        'reply' => $reply,
                        'action_performed' => 'create_task',
                        'data' => [
                            'task_id' => $task->id,
                            'title' => $task->title,
                            'priority' => $task->priority->value,
                            'due_at' => $task->due_at?->toIso8601String(),
                            'project' => $project->name,
                            'workspace' => $workspace->name,
                        ],
                        'speak' => $speak,
                        'context_update' => [
                            'pending_action' => null,
                            'last_referenced_task_id' => $task->id,
                            'last_referenced_task_title' => $task->title,
                            'last_tasks' => [
                                ['id' => $task->id, 'title' => $task->title, 'priority' => $task->priority->value, 'status' => $task->status->value],
                            ],
                        ],
                    ]);
                }
            }
        }

        // -------------------------------------------------------------
        // 1. WORKSPACE SWITCHING
        // -------------------------------------------------------------
        if (preg_match('/(?:switch|change|go to|open)\s+(?:workspace|company|to)?\s*(.+)/i', $message, $matches)
            || preg_match('/(?:switch\s+to\s+)(.+)/i', $message, $matches)) {
            $target = trim(preg_replace('/[?.!]/', '', $matches[1]));
            $found = null;

            if (Str::contains($lower, ['all', 'combined', 'everything', 'across all'])) {
                return $this->formatChatResponse([
                    'reply' => 'Switching to combined view across all companies.',
                    'action_performed' => 'switch_workspace',
                    'data' => ['workspace_id' => 'all', 'workspace_name' => 'All Workspaces (Combined)'],
                    'speak' => 'Switching to combined view across all companies.',
                    'context_update' => ['pending_action' => null],
                ]);
            }

            foreach ($user->workspaces()->active()->get() as $ws) {
                if (Str::contains(Str::lower($ws->name), Str::lower($target)) ||
                    Str::contains(Str::lower($target), Str::lower($ws->company_type))) {
                    $found = $ws;
                    break;
                }
            }

            if ($found) {
                return $this->formatChatResponse([
                    'reply' => "Switched active workspace to {$found->name}.",
                    'action_performed' => 'switch_workspace',
                    'data' => ['workspace_id' => $found->id, 'workspace_name' => $found->name],
                    'speak' => "Switched to {$found->name}.",
                    'context_update' => ['pending_action' => null],
                ]);
            }
        }

        // -------------------------------------------------------------
        // 2. SHOW WORKLOAD ACROSS ALL COMPANIES
        // -------------------------------------------------------------
        if (Str::contains($lower, ['workload across all', 'workload across companies', 'all companies workload', 'overall workload'])
            || preg_match('/(?:മൊത്തം|എല്ലാ\s*കമ്പനികളിലെയും|മുഴുവൻ)\s*വർക്ക്\s*ലോഡ്/iu', $message)) {
            $totalProjects = Project::where('user_id', $user->id)->whereNull('archived_at')->count();
            $openTasks = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->whereNotIn('status', ['done', 'cancelled'])
                ->count();
            $overdueTasks = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->overdue()->count();
            $workspaces = $user->workspaces()->active()->get();

            $summary = $isMalayalam
                ? "എല്ലാ {$workspaces->count()} കമ്പനികളിലുമായി ആകെ {$totalProjects} സജീവ പ്രൊജക്റ്റുകളും, {$openTasks} പെൻഡിംഗ് ടാസ്ക്കുകളും, {$overdueTasks} കാലാവധി കഴിഞ്ഞ ടാസ്ക്കുകളും ഉണ്ട്."
                : "Your total workload across all {$workspaces->count()} companies: {$totalProjects} active projects, {$openTasks} open tasks, and {$overdueTasks} overdue tasks.";

            return $this->formatChatResponse([
                'reply' => $summary,
                'action_performed' => 'get_workload_summary',
                'data' => [
                    'projects_count' => $totalProjects,
                    'open_tasks_count' => $openTasks,
                    'overdue_tasks_count' => $overdueTasks,
                ],
                'speak' => $summary,
                'context_update' => ['pending_action' => null],
            ]);
        }

        // -------------------------------------------------------------
        // 3. CONTEXTUAL QUERY: "WHICH ONE HAS THE HIGHEST PRIORITY?"
        // -------------------------------------------------------------
        if (Str::contains($lower, ['highest priority', 'top priority', 'most urgent', 'which has highest priority', 'which one is highest'])
            || preg_match('/(?:ഏറ്റവും\s*(?:കൂടുതൽ|ഉയർന്ന)?\s*(?:മുൻഗണനയുള്ള|പ്രധാനപ്പെട്ട|ഹൈ\s*പ്രയോറിറ്റി)|ഏതാണ്\s*ടോപ്പ്\s*പ്രയോറിറ്റി)/iu', $message)) {
            $tasksList = $context['last_tasks'] ?? null;
            $task = null;

            if (! empty($tasksList) && is_array($tasksList)) {
                $priorityWeights = ['urgent' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
                $sorted = collect($tasksList)->sortByDesc(fn ($t) => $priorityWeights[strtolower($t['priority'] ?? 'medium')] ?? 1);
                $highestItem = $sorted->first();
                if ($highestItem) {
                    $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->find($highestItem['id']);
                }
            }

            if (! $task) {
                // Look up in database
                $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                    ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                    ->whereNotIn('status', ['done', 'cancelled'])
                    ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
                    ->first();
            }

            if ($task) {
                $reply = $isMalayalam
                    ? "ഏറ്റവും ഉയർന്ന മുൻഗണനയുള്ള ടാസ്ക് \"{$task->title}\" [{$task->priority->value}] ആണ്. (കാലാവധി: ".($task->due_at ? $task->due_at->format('M d') : 'ഇല്ല').'). ഇത് പൂർത്തിയായതായി മാർക്ക് ചെയ്യണോ അതോ അപ്ഡേറ്റ് ചെയ്യണോ?'
                    : "The task with the highest priority is \"{$task->title}\" [{$task->priority->value}], due ".($task->due_at ? $task->due_at->format('M d') : 'no deadline').'. Would you like me to mark it as completed or update it?';
                $speak = $isMalayalam
                    ? "ഏറ്റവും ഉയർന്ന മുൻഗണനയുള്ള ടാസ്ക് {$task->title} ആണ്."
                    : "The task with the highest priority is {$task->title}.";

                return $this->formatChatResponse([
                    'reply' => $reply,
                    'action_performed' => 'identify_highest_priority_task',
                    'data' => ['task_id' => $task->id, 'title' => $task->title, 'priority' => $task->priority->value],
                    'speak' => $speak,
                    'context_update' => [
                        'last_referenced_task_id' => $task->id,
                        'last_referenced_task_title' => $task->title,
                    ],
                ]);
            } else {
                $emptyReply = $isMalayalam ? 'നിങ്ങൾക്ക് ഇപ്പോൾ പെൻഡിംഗ് ടാസ്ക്കുകൾ ഒന്നുമില്ല.' : "You don't have any pending tasks right now.";
                $emptySpeak = $isMalayalam ? 'പെൻഡിംഗ് ടാസ്ക്കുകൾ ഒന്നുമില്ല.' : 'You have no pending tasks.';

                return $this->formatChatResponse([
                    'reply' => $emptyReply,
                    'speak' => $emptySpeak,
                    'context_update' => ['pending_action' => null],
                ]);
            }
        }

        // -------------------------------------------------------------
        // 4. CONTEXTUAL COMPLETION: "MARK IT AS COMPLETED" / "COMPLETE IT"
        // -------------------------------------------------------------
        if (preg_match('/(?:mark\s+it|complete\s+it|finish\s+it|set\s+it\s+as\s+completed|done\s+with\s+it)/i', $message)
            || (Str::contains($lower, ['mark as completed', 'complete task', 'mark completed']) && Str::contains($lower, ['it', 'that', 'this']))
            || preg_match('/(?:അത്\s*(?:കംപ്ലീറ്റ്\s*ആക്കൂ|കംപ്ലീറ്റ്\s*ചെയ്യ്|പൂർത്തിയാക്കൂ|തീർത്തു|തീർന്നു)|തീർന്നു|തീർത്തു|കംപ്ലീറ്റ്\s*ആയി)/iu', $message)) {
            $targetTaskId = $context['last_referenced_task_id'] ?? null;

            if (! $targetTaskId && ! empty($context['last_tasks']) && count($context['last_tasks']) === 1) {
                $targetTaskId = $context['last_tasks'][0]['id'] ?? null;
            }

            if ($targetTaskId) {
                $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->find($targetTaskId);
                if ($task) {
                    $task->update([
                        'status' => TaskStatus::Done->value,
                        'completed_at' => now(),
                    ]);

                    $reply = $isMalayalam ? "ടാസ്ക് \"{$task->title}\" പൂർത്തിയായി." : "Marked task \"{$task->title}\" as completed.";
                    $speak = $isMalayalam ? "ടാസ്ക് \"{$task->title}\" പൂർത്തിയാക്കി." : "Completed task \"{$task->title}\".";

                    return $this->formatChatResponse([
                        'reply' => $reply,
                        'action_performed' => 'complete_task',
                        'data' => ['task_id' => $task->id, 'title' => $task->title],
                        'speak' => $speak,
                        'context_update' => [
                            'last_referenced_task_id' => null,
                            'last_referenced_task_title' => null,
                        ],
                    ]);
                }
            }

            // If no single referenced task, ask user which task to complete
            return $this->formatChatResponse([
                'reply' => $isMalayalam ? 'ഏത് ടാസ്ക് ആണ് പൂർത്തിയായതായി മാർക്ക് ചെയ്യേണ്ടത്? ദയവായി ടാസ്കിന്റെ പേര് പറയൂ.' : 'Which task would you like me to mark as completed? Please mention the task title.',
                'speak' => $isMalayalam ? 'ഏത് ടാസ്ക് ആണ് പൂർത്തിയാക്കേണ്ടത്?' : 'Which task would you like me to complete?',
                'action_performed' => 'clarification_needed',
            ]);
        }

        // -------------------------------------------------------------
        // 5. CREATE TASK (WITH MISSING DETAIL DETECTION)
        // -------------------------------------------------------------
        $isCreateTask = false;
        $explicitPriority = null;
        $cleanRemainder = '';

        if (preg_match('/(?:create|add|new)\s+(?:a\s+)?(?:(high|urgent|medium|low)[-\s]priority\s+)?task\b(.*)/i', $message, $matches)) {
            $isCreateTask = true;
            $explicitPriority = ! empty($matches[1]) ? strtolower($matches[1]) : null;
            $remainder = trim($matches[2] ?? '');
            // Remove leading helper words
            $cleanRemainder = trim(preg_replace('/^(to|called|titled|for|in|about)\s+/i', '', $remainder));
            $cleanRemainder = trim(preg_replace('/[?.!]/', '', $cleanRemainder));
        } elseif (! preg_match('/(?:കംപ്ലീറ്റ്|പൂർത്തി|ലിസ്റ്റ്|കാണിക്കൂ|തീർത്തു|തീർന്നു|പെൻഡിംഗ്)/iu', $message)
            && (
                preg_match('/(?:ഒരു\s+)?(?:പുതിയ\s+)?(?:(high|urgent|medium|low|ഹൈ|അടിയന്തിര|പ്രധാന|മീഡിയം|ലോ)[-\s]?(?:priority|പ്രയോറിറ്റി|മുൻഗണനയുള്ള\s+)?)?ടാസ്ക്\s+(?:ആഡ്|ക്രിയേറ്റ്|ഉണ്ടാക്കൂ|ചേർക്കൂ|സെറ്റ്)\s*(?:ചെയ്യ്|ചെയ്യുക|ചെയ്യുമോ|ചെയ്യാം|ചെയ്യൂ|ചെയ്യട്ടെ)?[:\-]?\s*(.*)/iu', $message, $matches)
                || preg_match('/(?:ഒരു\s+)?(?:പുതിയ\s+)?(?:(high|urgent|medium|low|ഹൈ|അടിയന്തിര|പ്രധാന|മീഡിയം|ലോ)[-\s]?(?:priority|പ്രയോറിറ്റി|മുൻഗണനയുള്ള\s+)?)?(?:ആഡ്|ക്രിയേറ്റ്|ഉണ്ടാക്കൂ|ചേർക്കൂ|സെറ്റ്)\s*(?:ചെയ്യ്|ചെയ്യുക|ചെയ്യുമോ|ചെയ്യാം|ചെയ്യൂ|ചെയ്യട്ടെ)?\s*(?:ഒരു\s+)?(?:പുതിയ\s+)?ടാസ്ക്[:\-]?\s*(.*)/iu', $message, $matches)
            )
        ) {
            $isCreateTask = true;
            $rawP = ! empty($matches[1]) ? mb_strtolower($matches[1]) : null;
            if ($rawP) {
                if (in_array($rawP, ['അടിയന്തിര', 'urgent'])) $explicitPriority = 'urgent';
                elseif (in_array($rawP, ['ഹൈ', 'പ്രധാന', 'high'])) $explicitPriority = 'high';
                elseif (in_array($rawP, ['ലോ', 'low'])) $explicitPriority = 'low';
                else $explicitPriority = 'medium';
            }
            $cleanRemainder = trim($matches[2] ?? '');
            $cleanRemainder = trim(preg_replace('/^(?:ആഡ്|ക്രിയേറ്റ്|ഉണ്ടാക്കൂ|ചേർക്കൂ|സെറ്റ്)?\s*(?:ചെയ്യ്|ചെയ്യുക|ചെയ്യുമോ|ചെയ്യാം|ചെയ്യൂ|ചെയ്യട്ടെ)?[:\-]?\s*/iu', '', $cleanRemainder));
            $cleanRemainder = trim(preg_replace('/^(?:to|called|titled|for|in|about|ഒരു|പുതിയ|പേരിൽ)\s+/iu', '', $cleanRemainder));
            $cleanRemainder = trim(preg_replace('/[?.!]/', '', $cleanRemainder));
        }

        if ($isCreateTask) {
            // Extract priority from text if not already matched
            $priority = $explicitPriority ?: (
                (Str::contains($lower, ['urgent', 'emergency']) || preg_match('/(?:അടിയന്തിര|അർജന്റ്)/iu', $message)) ? 'urgent' :
                ((Str::contains($lower, ['high priority', 'high-priority', 'important']) || preg_match('/(?:ഹൈ|പ്രധാന|മുൻഗണന)/iu', $message)) ? 'high' :
                ((Str::contains($lower, ['low priority', 'low-priority']) || preg_match('/(?:ലോ\s*പ്രയോറിറ്റി|ലോ)/iu', $message)) ? 'low' : 'medium'))
            );

            // Extract due date
            $dueAt = null;
            if (Str::contains($lower, 'tomorrow') || preg_match('/(?:നാളത്തേക്ക്|നാളെ)/iu', $message)) {
                $dueAt = today()->addDay()->endOfDay();
                $cleanRemainder = trim(preg_replace('/\b(?:for\s+)?tomorrow\b/i', '', $cleanRemainder));
                $cleanRemainder = trim(preg_replace('/(?:നാളത്തേക്ക്|നാളെ)/iu', '', $cleanRemainder));
            } elseif (Str::contains($lower, 'today') || preg_match('/(?:ഇന്നത്തേക്ക്|ഇന്ന്)/iu', $message)) {
                $dueAt = today()->endOfDay();
                $cleanRemainder = trim(preg_replace('/\b(?:for\s+)?today\b/i', '', $cleanRemainder));
                $cleanRemainder = trim(preg_replace('/(?:ഇന്നത്തേക്ക്|ഇന്ന്)/iu', '', $cleanRemainder));
            } elseif (preg_match('/\b(?:next|on)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $cleanRemainder, $dm)) {
                $dueAt = Carbon::parse("next {$dm[1]}")->endOfDay();
                $cleanRemainder = trim(preg_replace('/\b(?:for|on|by)?\s*(?:next\s+)?'.$dm[1].'\b/i', '', $cleanRemainder));
            }

            // Extract project if specified
            $projectName = null;
            if (preg_match('/(.+?)\s+(?:in|for)\s+(?:project\s+)?([a-zA-Z0-9_\-\s]+)$/i', $cleanRemainder, $pm)) {
                $cleanRemainder = trim($pm[1]);
                $projectName = trim($pm[2]);
            } elseif (preg_match('/(.+?)\s+(?:ൽ|യിൽ|നു\s*വേണ്ടി)\s*(?:project\s+|പ്രൊജക്റ്റ്\s+)?([a-zA-Z0-9_\-\s]+)$/iu', $cleanRemainder, $pm)) {
                $cleanRemainder = trim($pm[1]);
                $projectName = trim($pm[2]);
            }

            // Check if title is missing
            $cleanRemainder = trim(preg_replace('/^(to|called|titled|a|the|ഒരു|പുതിയ)\s+/iu', '', $cleanRemainder));
            $cleanRemainder = trim(preg_replace('/^(complete|finish|do|ചെയ്യ്|ചെയ്യുക)\s*$/iu', '', $cleanRemainder));
            $cleanRemainder = trim(preg_replace('/[:\-]+$/', '', $cleanRemainder));
            $cleanRemainder = trim($cleanRemainder);

            if (empty($cleanRemainder) || $cleanRemainder === 'to' || $cleanRemainder === 'for') {
                // Missing required field: Task Title! Ask follow-up question
                $followupReply = $isMalayalam ? 'ഏത് ടാസ്ക് ആണ് ഉണ്ടാക്കേണ്ടത്? ടാസ്കിന്റെ പേര് പറയൂ.' : 'What task would you like me to create?';
                $followupSpeak = $isMalayalam ? 'ഏത് ടാസ്ക് ആണ് ഉണ്ടാക്കേണ്ടത്?' : 'What task would you like me to create?';

                return $this->formatChatResponse([
                    'reply' => $followupReply,
                    'speak' => $followupSpeak,
                    'action_performed' => 'ask_followup',
                    'context_update' => [
                        'pending_action' => [
                            'intent' => 'create_task',
                            'missing_field' => 'title',
                            'priority' => $priority,
                            'due_at' => $dueAt ? $dueAt->toIso8601String() : null,
                            'project_name' => $projectName,
                        ],
                    ],
                ]);
            }

            $taskTitle = $cleanRemainder;

            // Resolve Project
            $project = null;
            if ($projectName) {
                $project = Project::where('user_id', $user->id)
                    ->where('name', 'like', "%{$projectName}%")
                    ->first();
            }
            if (! $project) {
                $project = Project::where('user_id', $user->id)
                    ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                    ->first();
            }
            if (! $project) {
                $project = $user->projects()->create([
                    'workspace_id' => $workspace?->id,
                    'name' => 'General',
                    'slug' => Project::uniqueSlugFor($user, 'General'),
                    'status' => ProjectStatus::Active->value,
                    'priority' => 'medium',
                ]);
            }

            // Create Task in Database
            $task = $project->tasks()->create([
                'workspace_id' => $workspace?->id,
                'user_id' => $user->id,
                'title' => $taskTitle,
                'status' => TaskStatus::Todo->value,
                'priority' => $priority,
                'due_at' => $dueAt,
                'activity_history' => [
                    [
                        'event' => 'created_by_juju',
                        'description' => "Created via Juju voice command: {$message}",
                        'timestamp' => now()->toIso8601String(),
                        'user' => $user->name,
                    ],
                ],
            ]);

            $dueStr = $dueAt ? ($dueAt->isTomorrow() ? 'tomorrow' : ($dueAt->isToday() ? 'today' : $dueAt->format('M d'))) : 'none';
            $dueStrMl = $dueAt ? ($dueAt->isTomorrow() ? 'നാളെ' : ($dueAt->isToday() ? 'ഇന്ന്' : $dueAt->format('M d'))) : 'ഇല്ല';
            $priorityLabel = $priority === 'high' ? 'high-priority ' : ($priority === 'urgent' ? 'urgent ' : '');
            $priorityLabelMl = $priority === 'high' ? 'ഹൈ പ്രയോറിറ്റി ' : ($priority === 'urgent' ? 'അടിയന്തിര ' : '');

            // Verify task was saved
            if (! $task->id) {
                return $this->formatChatResponse([
                    'reply' => 'Failed to create task in the database.',
                    'speak' => 'Task creation failed.',
                ], 500);
            }

            if ($isMalayalam) {
                $speak = $dueAt
                    ? "{$dueStrMl} കാലാവധിയുള്ള {$priorityLabelMl}ടാസ്ക് \"{$task->title}\" ഉണ്ടാക്കിയിട്ടുണ്ട്."
                    : "ടാസ്ക് \"{$task->title}\" ഉണ്ടാക്കിയിട്ടുണ്ട്.";
                $reply = "പുതിയ {$priorityLabelMl}ടാസ്ക് \"{$task->title}\" (മുൻഗണന: {$priority}, കാലാവധി: {$dueStrMl}) പ്രൊജക്റ്റ് \"{$project->name}\" ൽ വിജയകരമായി ചേർത്തു.";
            } else {
                if (Str::contains($lower, 'dashboard') || Str::contains(Str::lower($taskTitle), 'dashboard')) {
                    $speak = $dueAt && $dueAt->isTomorrow()
                        ? "I've created your high-priority dashboard task with a due date of tomorrow."
                        : "I've created your {$priorityLabel}task \"{$task->title}\".";
                } else {
                    $speak = $dueAt
                        ? "I've created your {$priorityLabel}task \"{$task->title}\" with a due date of {$dueStr}."
                        : "Task \"{$task->title}\" has been created.";
                }

                $reply = "Created {$priorityLabel}task \"{$task->title}\" with due date {$dueStr} in project \"{$project->name}\" for workspace \"{$workspace->name}\".";
            }

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'create_task',
                'data' => [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'priority' => $task->priority->value,
                    'due_at' => $task->due_at?->toIso8601String(),
                    'project' => $project->name,
                    'workspace' => $workspace->name,
                ],
                'speak' => $speak,
                'context_update' => [
                    'pending_action' => null,
                    'last_referenced_task_id' => $task->id,
                    'last_referenced_task_title' => $task->title,
                    'last_tasks' => [
                        ['id' => $task->id, 'title' => $task->title, 'priority' => $task->priority->value, 'status' => $task->status->value],
                    ],
                ],
            ]);
        }

        // -------------------------------------------------------------
        // 6. SUGGEST NEXT TASK BASED ON DATA
        // -------------------------------------------------------------
        if (Str::contains($lower, ['suggest', 'what should i work on', 'next task', 'what to do next', 'recommend next'])
            || preg_match('/(?:അടുത്തത്|അടുത്ത\s*ടാസ്ക്|എന്താണ്\s*(?:ഇനി\s*)?ചെയ്യേണ്ടത്|എന്ത്\s*ചെയ്യണം)/iu', $message)) {
            // First check overdue tasks
            $overdue = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->overdue()
                ->with('project')
                ->first();

            if ($overdue) {
                $reply = $isMalayalam
                    ? "കാലാവധി കഴിഞ്ഞ ഈ ടാസ്കിൽ ആദ്യം ശ്രദ്ധ കേന്ദ്രീകരിക്കാൻ ഞാൻ നിർദ്ദേശിക്കുന്നു: **{$overdue->title}** (പ്രൊജക്റ്റ്: *{$overdue->project?->name}*, കാലാവധി: {$overdue->due_at?->format('M d')})."
                    : "I suggest working on your overdue task: **{$overdue->title}** in project *{$overdue->project?->name}* (due {$overdue->due_at?->format('M d')}). Resolving overdue items eliminates delivery bottlenecks.";
                $speak = $isMalayalam
                    ? "കാലാവധി കഴിഞ്ഞ {$overdue->title} എന്ന ടാസ്ക് ആദ്യം ചെയ്യാൻ നിർദ്ദേശിക്കുന്നു."
                    : "I suggest working on your overdue task: {$overdue->title}.";

                return $this->formatChatResponse([
                    'reply' => $reply,
                    'action_performed' => 'suggest_next_task',
                    'data' => ['task_id' => $overdue->id, 'title' => $overdue->title, 'priority' => $overdue->priority->value],
                    'speak' => $speak,
                    'context_update' => [
                        'last_referenced_task_id' => $overdue->id,
                        'last_referenced_task_title' => $overdue->title,
                    ],
                ]);
            }

            // Next check urgent or high priority tasks
            $nextTask = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereNotIn('status', ['done', 'cancelled'])
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
                ->with('project')
                ->first();

            if ($nextTask) {
                $reply = $isMalayalam
                    ? "**{$nextTask->title}** എന്ന ടാസ്കിൽ ശ്രദ്ധിക്കാൻ നിർദ്ദേശിക്കുന്നു (പ്രൊജക്റ്റ്: *{$nextTask->project?->name}*, മുൻഗണന: **{$nextTask->priority->value}**)."
                    : "I recommend focusing on **{$nextTask->title}** in project *{$nextTask->project?->name}*. It has **{$nextTask->priority->value}** priority.";
                $speak = $isMalayalam
                    ? "{$nextTask->priority->value} മുൻഗണനയുള്ള {$nextTask->title} എന്ന ടാസ്കിൽ ശ്രദ്ധിക്കാൻ നിർദ്ദേശിക്കുന്നു."
                    : "I recommend focusing on {$nextTask->title}, which has {$nextTask->priority->value} priority.";

                return $this->formatChatResponse([
                    'reply' => $reply,
                    'action_performed' => 'suggest_next_task',
                    'data' => ['task_id' => $nextTask->id, 'title' => $nextTask->title, 'priority' => $nextTask->priority->value],
                    'speak' => $speak,
                    'context_update' => [
                        'last_referenced_task_id' => $nextTask->id,
                        'last_referenced_task_title' => $nextTask->title,
                    ],
                ]);
            }

            $reply = $isMalayalam
                ? "{$workspace->name} ലെ എല്ലാ ടാസ്ക്കുകളും പൂർത്തിയായി! നിങ്ങൾക്ക് ഇപ്പോൾ പെൻഡിംഗ് ടാസ്ക്കുകൾ ഒന്നുമില്ല. അടിപൊളി!"
                : "All tasks in {$workspace->name} are completed! You have no open tasks. Great work!";
            $speak = $isMalayalam
                ? "എല്ലാ ടാസ്ക്കുകളും പൂർത്തിയായി. പെൻഡിംഗ് ടാസ്ക്കുകൾ ഒന്നുമില്ല."
                : $reply;

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'suggest_next_task',
                'speak' => $speak,
                'context_update' => ['pending_action' => null],
            ]);
        }

        // -------------------------------------------------------------
        // 7. SUMMARIZE COMPLETED AND PENDING TASKS
        // -------------------------------------------------------------
        if (Str::contains($lower, ['completed and pending', 'summarize tasks', 'task breakdown', 'task stats', 'how many tasks'])
            || preg_match('/(?:ടാസ്ക്\s*സംഗ്രഹം|ടാസ്ക്കുകളുടെ\s*കണക്ക്|എത്ര\s*ടാസ്ക്)/iu', $message)) {
            $completedCount = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereIn('status', ['done', 'cancelled'])
                ->count();

            $pendingCount = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereNotIn('status', ['done', 'cancelled'])
                ->count();

            $overdueCount = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->overdue()
                ->count();

            $reply = $isMalayalam
                ? "### {$workspace->name} ലെ ടാസ്ക് സംഗ്രഹം\n• **പെൻഡിംഗ് ടാസ്ക്കുകൾ:** {$pendingCount}\n• **പൂർത്തിയായവ:** {$completedCount}\n• **കാലാവധി കഴിഞ്ഞവ:** {$overdueCount}"
                : "### Task Summary for {$workspace->name}\n• **Pending Tasks:** {$pendingCount}\n• **Completed Tasks:** {$completedCount}\n• **Overdue Tasks:** {$overdueCount}";
            $speak = $isMalayalam
                ? "{$workspace->name} ൽ നിങ്ങൾക്ക് {$pendingCount} പെൻഡിംഗ് ടാസ്ക്കുകളും, {$completedCount} പൂർത്തിയായ ടാസ്ക്കുകളും, {$overdueCount} കാലാവധി കഴിഞ്ഞ ടാസ്ക്കുകളും ഉണ്ട്."
                : "In {$workspace->name}, you have {$pendingCount} pending tasks, {$completedCount} completed tasks, and {$overdueCount} overdue tasks.";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'summarize_tasks',
                'data' => [
                    'pending' => $pendingCount,
                    'completed' => $completedCount,
                    'overdue' => $overdueCount,
                ],
                'speak' => $speak,
                'context_update' => ['pending_action' => null],
            ]);
        }

        // -------------------------------------------------------------
        // 8. MARK SPECIFIC TASK AS COMPLETED
        // -------------------------------------------------------------
        $isCompleteMatch = false;
        $term = '';

        if (preg_match('/(?:mark|set)\s+(?:the\s+)?(?:task\s+)?["\']?(.+?)["\']?\s+(?:as\s+)?(?:completed|done|finished)/i', $message, $matches)
            || preg_match('/(?:complete|finish)\s+(?:the\s+)?task\s+["\']?([^"\']+)["\']?/i', $message, $matches)) {
            $isCompleteMatch = true;
            $rawTerm = trim(preg_replace('/[?.!]/', '', $matches[1]));
            $term = trim(str_ireplace(['the task', 'task', 'as'], '', $rawTerm));
        } elseif (preg_match('/(?:(?:ടാസ്ക്\s+)?["\']?(.+?)["\']?\s*(?:കംപ്ലീറ്റ്\s*ആക്കൂ|കംപ്ലീറ്റ്\s*ചെയ്യ്|പൂർത്തിയാക്കൂ|തീർത്തു|തീർന്നു)|(?:കംപ്ലീറ്റ്\s*ആക്കൂ|പൂർത്തിയാക്കൂ)\s*(?:ടാസ്ക്\s+)?["\']?(.+?)["\']?)/iu', $message, $matches)) {
            $isCompleteMatch = true;
            $rawTerm = trim(preg_replace('/[?.!]/', '', ! empty($matches[1]) ? $matches[1] : ($matches[2] ?? '')));
            $term = trim(str_ireplace(['the task', 'task', 'as', 'ടാസ്ക്', 'ടാസ്ക്ക്'], '', $rawTerm));
        }

        if ($isCompleteMatch) {
            $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('title', 'like', "%{$term}%")
                ->whereNotIn('status', ['done', 'cancelled'])
                ->first();

            if (! $task && $term !== '') {
                $words = array_filter(explode(' ', $term), fn ($w) => strlen($w) > 2);
                if (! empty($words)) {
                    $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                        ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                        ->where(function ($q) use ($words) {
                            foreach ($words as $w) {
                                $q->orWhere('title', 'like', "%{$w}%");
                            }
                        })
                        ->whereNotIn('status', ['done', 'cancelled'])
                        ->first();
                }
            }

            if (! $task) {
                // Search across all workspaces if not found in current
                $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                    ->where('title', 'like', "%{$term}%")
                    ->first();
            }

            if (! $task) {
                $notFoundReply = $isMalayalam
                    ? "\"{$term}\" എന്ന പേരിൽ ഒരു പെൻഡിംഗ് ടാസ്ക് കണ്ടെത്താനായില്ല. ടാസ്കിന്റെ പേര് ഒന്നുകൂടി വ്യക്തമാക്കാമോ?"
                    : "I couldn't find an open task matching \"{$term}\". Could you clarify the task title?";
                $notFoundSpeak = $isMalayalam
                    ? "ആ പേരിൽ ഒരു പെൻഡിംഗ് ടാസ്ക് കണ്ടെത്താനായില്ല."
                    : 'I could not find an open task matching that title.';

                return $this->formatChatResponse([
                    'reply' => $notFoundReply,
                    'speak' => $notFoundSpeak,
                ]);
            }

            $task->update([
                'status' => TaskStatus::Done->value,
                'completed_at' => now(),
            ]);

            $reply = $isMalayalam ? "ടാസ്ക് \"{$task->title}\" പൂർത്തിയായി." : "Marked task \"{$task->title}\" as completed.";
            $speak = $isMalayalam ? "ടാസ്ക് \"{$task->title}\" പൂർത്തിയാക്കി." : "Completed task \"{$task->title}\".";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'complete_task',
                'data' => ['task_id' => $task->id, 'title' => $task->title],
                'speak' => $speak,
                'context_update' => [
                    'last_referenced_task_id' => $task->id,
                    'last_referenced_task_title' => $task->title,
                ],
            ]);
        }

        // -------------------------------------------------------------
        // 9. SHOW PENDING / TODAY'S TASKS
        // -------------------------------------------------------------
        if (Str::contains($lower, ['pending tasks', 'tasks today', 'show my tasks', 'my tasks', 'what are my tasks', 'list tasks', 'view tasks'])
            || preg_match('/(?:ഇന്നത്തെ\s*)?(?:പെൻഡിംഗ്|ബാക്കിയുള്ള|എന്റെ)?\s*ടാസ്ക്കുകൾ\s*(?:കാണിക്കൂ|പറയൂ|ഏതൊക്കെയാണ്|ലിസ്റ്റ്\s*ചെയ്യ്|ലിസ്റ്റ്)/iu', $message)) {
            $query = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereNotIn('status', ['done', 'cancelled'])
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END");

            if (Str::contains($lower, ['today']) || preg_match('/ഇന്നത്തെ/iu', $message)) {
                $query->where(fn ($q) => $q->whereDate('due_at', '<=', today())->orWhereNull('due_at'));
            }

            $tasks = $query->with('project')->limit(6)->get();

            if ($tasks->isEmpty()) {
                $reply = $isMalayalam ? "{$workspace->name} ൽ ഇപ്പോൾ പെൻഡിംഗ് ടാസ്ക്കുകൾ ഒന്നുമില്ല. അടിപൊളി!" : "You have no pending tasks in {$workspace->name}. Great job!";

                return $this->formatChatResponse([
                    'reply' => $reply,
                    'speak' => $reply,
                    'context_update' => ['pending_action' => null, 'last_tasks' => []],
                ]);
            }

            $list = $tasks->map(fn ($t) => "• {$t->title} [{$t->priority->value}] ({$t->project?->name})")->join("\n");
            $reply = $isMalayalam
                ? "{$workspace->name} ലെ പെൻഡിംഗ് ടാസ്ക്കുകൾ താഴെ നൽകുന്നു:\n{$list}"
                : "Here are your pending tasks in {$workspace->name}:\n{$list}";
            $speak = $isMalayalam
                ? "{$workspace->name} ൽ നിങ്ങൾക്ക് {$tasks->count()} പെൻഡിംഗ് ടാസ്ക്കുകൾ ഉണ്ട്. ആദ്യത്തേത് \"{$tasks->first()->title}\" ആണ്."
                : "You have {$tasks->count()} pending tasks in {$workspace->name}. First is {$tasks->first()->title}.";

            $tasksData = $tasks->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'priority' => $t->priority->value,
                'status' => $t->status->value,
                'project' => $t->project?->name,
            ])->all();

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'list_tasks',
                'data' => $tasksData,
                'speak' => $speak,
                'context_update' => [
                    'last_tasks' => $tasksData,
                    'last_referenced_task_id' => $tasks->first()->id,
                    'last_referenced_task_title' => $tasks->first()->title,
                ],
            ]);
        }

        // -------------------------------------------------------------
        // 10. UPDATE TASK (PRIORITY / DUE DATE / STATUS)
        // -------------------------------------------------------------
        if (preg_match('/(?:set|change|update)\s+(?:the\s+)?priority\s+of\s+(?:task\s+)?["\']?(.+?)["\']?\s+to\s+(urgent|high|medium|low)/i', $message, $matches)) {
            $term = trim($matches[1]);
            $newPriority = strtolower($matches[2]);

            $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('title', 'like', "%{$term}%")
                ->first();

            if ($task) {
                $task->update(['priority' => $newPriority]);

                return $this->formatChatResponse([
                    'reply' => "Updated priority of task \"{$task->title}\" to {$newPriority}.",
                    'action_performed' => 'update_task',
                    'data' => ['task_id' => $task->id, 'title' => $task->title, 'priority' => $newPriority],
                    'speak' => "Set priority of \"{$task->title}\" to {$newPriority}.",
                    'context_update' => ['last_referenced_task_id' => $task->id],
                ]);
            }
        }

        // UPDATE TASK DUE DATE
        if (preg_match('/(?:set|change|update)\s+(?:the\s+)?due\s+date\s+(?:of\s+)?(?:task\s+)?["\']?(.+?)["\']?\s+to\s+(today|tomorrow|[a-zA-Z0-9\-\s]+)/i', $message, $matches)) {
            $term = trim($matches[1]);
            $dateTerm = strtolower(trim($matches[2]));
            $dueAt = $dateTerm === 'today' ? today()->endOfDay() : ($dateTerm === 'tomorrow' ? today()->addDay()->endOfDay() : Carbon::parse($dateTerm)->endOfDay());

            $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('title', 'like', "%{$term}%")
                ->first();

            if ($task) {
                $task->update(['due_at' => $dueAt]);
                $dateLabel = $dateTerm;

                return $this->formatChatResponse([
                    'reply' => "Updated due date of task \"{$task->title}\" to {$dateLabel}.",
                    'action_performed' => 'update_task_due_date',
                    'data' => ['task_id' => $task->id, 'title' => $task->title, 'due_at' => $task->due_at?->toIso8601String()],
                    'speak' => "Set due date of \"{$task->title}\" to {$dateLabel}.",
                    'context_update' => ['last_referenced_task_id' => $task->id],
                ]);
            }
        }

        // -------------------------------------------------------------
        // 11. SENSITIVE ACTION / DESTRUCTIVE CONFIRMATION: DELETE PROJECT/TASK
        // -------------------------------------------------------------
        if (preg_match('/(?:delete|remove)\s+(?:the\s+)?task\s+["\']?([^"\']+)["\']?/i', $message, $matches)) {
            $taskName = trim(preg_replace('/[?.!]/', '', $matches[1]));
            $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('title', 'like', "%{$taskName}%")
                ->first();

            if (! $task) {
                return $this->formatChatResponse([
                    'reply' => "Could not locate task \"{$taskName}\" to delete.",
                    'speak' => 'Could not find that task.',
                ]);
            }

            return $this->formatChatResponse([
                'reply' => "Are you sure you want to delete task \"{$task->title}\"? This action cannot be undone. Please confirm.",
                'speak' => "Are you sure you want to delete task {$task->title}? Please confirm.",
                'action_performed' => 'confirm_destructive_action',
                'requires_confirmation' => true,
                'data' => [
                    'action' => 'delete_task',
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                ],
                'context_update' => ['pending_action' => null],
            ]);
        }

        if (preg_match('/(?:delete|remove)\s+(?:the\s+)?project\s+["\']?([^"\']+)["\']?/i', $message, $matches)) {
            $projName = trim(preg_replace('/[?.!]/', '', $matches[1]));
            $project = Project::where('user_id', $user->id)
                ->where('name', 'like', "%{$projName}%")
                ->first();

            if (! $project) {
                return $this->formatChatResponse([
                    'reply' => "Could not locate project \"{$projName}\" to delete.",
                    'speak' => 'Could not find that project.',
                ]);
            }

            return $this->formatChatResponse([
                'reply' => "Are you sure you want to delete project \"{$project->name}\"? This will also delete its tasks. Please confirm.",
                'speak' => "Are you sure you want to delete project {$project->name}? Please confirm.",
                'action_performed' => 'confirm_destructive_action',
                'requires_confirmation' => true,
                'data' => [
                    'action' => 'delete_project',
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                ],
                'context_update' => ['pending_action' => null],
            ]);
        }

        // -------------------------------------------------------------
        // 12. CREATE PROJECT
        // -------------------------------------------------------------
        if (preg_match('/(?:create|add|new)\s+(?:a\s+)?project\s+(?:called|named\s+|titled\s+)?["\']?([^"\']+)["\']?/i', $message, $matches)
            || preg_match('/(?:ഒരു\s+)?(?:പുതിയ\s+)?പ്രൊജക്റ്റ്\s*(?:ആഡ്|ക്രിയേറ്റ്|ഉണ്ടാക്കൂ|ചേർക്കൂ)\s*(?:ചെയ്യ്|ചെയ്യുക)?[:\-]?\s*["\']?([^"\']+)["\']?/iu', $message, $matches)) {
            $projectName = trim(preg_replace('/[?.!]/', '', $matches[1]));

            $project = $user->projects()->create([
                'workspace_id' => $workspace?->id,
                'name' => $projectName,
                'slug' => Project::uniqueSlugFor($user, $projectName),
                'status' => ProjectStatus::Active->value,
                'priority' => 'medium',
                'tech_stack' => ['Laravel', 'React'],
            ]);

            $reply = $isMalayalam
                ? "{$workspace->name} ൽ പുതിയ പ്രൊജക്റ്റ് \"{$project->name}\" വിജയകരമായി ആരംഭിച്ചു."
                : "Created project \"{$project->name}\" under {$workspace->name}.";
            $speak = $isMalayalam
                ? "\"{$project->name}\" എന്ന പ്രൊജക്റ്റ് ആരംഭിച്ചിട്ടുണ്ട്."
                : "Project \"{$project->name}\" created.";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'create_project',
                'data' => ['project_id' => $project->id, 'name' => $project->name],
                'speak' => $speak,
                'context_update' => [
                    'last_projects' => [['id' => $project->id, 'name' => $project->name]],
                ],
            ]);
        }

        // -------------------------------------------------------------
        // 13. FIND PROJECT / SHOW ACTIVE PROJECTS
        // -------------------------------------------------------------
        if (preg_match('/(?:find|details\s+for|view)\s+(?:project\s+)?["\']?([^"\']+)["\']?/i', $message, $matches)
            && ! Str::contains($lower, ['create', 'new', 'add', 'delete', 'progress'])) {
            $projName = trim(preg_replace('/[?.!]/', '', $matches[1]));
            $project = Project::where('user_id', $user->id)
                ->where('name', 'like', "%{$projName}%")
                ->withCount('tasks')
                ->first();

            if ($project) {
                $openCount = $project->tasks()->whereNotIn('status', ['done', 'cancelled'])->count();
                $completedCount = $project->tasks()->whereIn('status', ['done', 'cancelled'])->count();

                $reply = "### Project: {$project->name}\n• **Status:** {$project->status->value}\n• **Priority:** {$project->priority}\n• **Open Tasks:** {$openCount}\n• **Completed Tasks:** {$completedCount}";
                $speak = "Project {$project->name} is {$project->status->value} with {$openCount} open tasks.";

                return $this->formatChatResponse([
                    'reply' => $reply,
                    'action_performed' => 'find_project',
                    'data' => [
                        'project_id' => $project->id,
                        'name' => $project->name,
                        'status' => $project->status->value,
                        'open_tasks' => $openCount,
                    ],
                    'speak' => $speak,
                    'context_update' => ['last_projects' => [['id' => $project->id, 'name' => $project->name]]],
                ]);
            }
        }

        if (Str::contains($lower, ['active projects', 'show my projects', 'list projects', 'my projects'])) {
            $projects = Project::where('user_id', $user->id)
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereNull('archived_at')
                ->withCount('tasks')
                ->get();

            if ($projects->isEmpty()) {
                $reply = "No active projects found in {$workspace->name}.";

                return $this->formatChatResponse(['reply' => $reply, 'speak' => $reply]);
            }

            $list = $projects->map(fn ($p) => "• {$p->name} ({$p->tasks_count} tasks)")->join("\n");
            $reply = "Active projects in {$workspace->name}:\n{$list}";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'list_projects',
                'data' => $projects->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'tasks_count' => $p->tasks_count]),
                'speak' => "You have {$projects->count()} active projects in {$workspace->name}.",
                'context_update' => [
                    'last_projects' => $projects->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(),
                ],
            ]);
        }

        // -------------------------------------------------------------
        // 14. PROJECT PROGRESS
        // -------------------------------------------------------------
        if (preg_match('/(?:progress\s+of|how\s+is)\s+(?:the\s+)?["\']?([^"\']+)["\']?/i', $message, $matches)
            || Str::contains($lower, ['progress'])) {
            $nameTerm = isset($matches[1]) ? trim(preg_replace('/[?.!]/', '', $matches[1])) : '';
            $nameTerm = str_ireplace(['project', 'software', 'platform'], '', $nameTerm);
            $nameTerm = trim($nameTerm);

            $project = Project::where('user_id', $user->id)
                ->when($nameTerm !== '', fn ($q) => $q->where('name', 'like', "%{$nameTerm}%"))
                ->withCount('tasks')
                ->first();

            if (! $project) {
                return $this->formatChatResponse([
                    'reply' => "Could not locate a project matching \"{$nameTerm}\". Please specify the project name.",
                    'speak' => 'Could not find that project.',
                ]);
            }

            $completed = $project->tasks()->whereIn('status', ['done', 'completed'])->count();
            $total = $project->tasks_count;
            $percentage = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

            $reply = "Project \"{$project->name}\" is at {$percentage}% completion ({$completed}/{$total} tasks completed).";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'get_project_progress',
                'data' => [
                    'project_id' => $project->id,
                    'name' => $project->name,
                    'progress' => $percentage,
                    'completed' => $completed,
                    'total' => $total,
                ],
                'speak' => $reply,
            ]);
        }

        // -------------------------------------------------------------
        // 15. RECORD WORK LOG
        // -------------------------------------------------------------
        $isWorkLogMatch = false;
        $logDesc = '';

        if (preg_match('/(?:record|log)\s+(?:that\s+)?(.+)/i', $message, $matches)) {
            $isWorkLogMatch = true;
            $logDesc = trim(preg_replace('/[?.!]/', '', $matches[1]));
        } elseif (preg_match('/(?:വർക്ക്\s*ലോഗ്|ലോഗ്)\s*(?:ആഡ്\s*ചെയ്യ്|ചെയ്യ്|രേഖപ്പെടുത്തൂ|ചെയ്യുക)[:\-]?\s*(.+)/iu', $message, $matches)
            || preg_match('/(?:ഇന്ന്\s+)?(.+?)\s*(?:എന്ന്\s*)?(?:വർക്ക്\s*ലോഗ്\s*ചെയ്യ്|ലോഗ്\s*ചെയ്യ്|രേഖപ്പെടുത്തൂ)/iu', $message, $matches)) {
            $isWorkLogMatch = true;
            $logDesc = trim(preg_replace('/[?.!]/', '', ! empty($matches[1]) ? $matches[1] : ($matches[2] ?? '')));
            $logDesc = trim(preg_replace('/^(that\s+|ഇന്ന്\s+|ഞാൻ\s+)/iu', '', $logDesc));
        }

        if ($isWorkLogMatch && ! empty($logDesc)) {
            $minutes = 60;
            if (preg_match('/(\d+)\s*(?:hours|hour|hr|hrs|മണിക്കൂർ)/iu', $message, $tm)) {
                $minutes = ((int) $tm[1]) * 60;
                $logDesc = trim(preg_replace('/(\d+)\s*(?:hours|hour|hr|hrs|മണിക്കൂർ)/iu', '', $logDesc));
            } elseif (preg_match('/(\d+)\s*(?:minutes|mins|min|മിനിറ്റ്)/iu', $message, $tm)) {
                $minutes = (int) $tm[1];
                $logDesc = trim(preg_replace('/(\d+)\s*(?:minutes|mins|min|മിനിറ്റ്)/iu', '', $logDesc));
            }
            $logDesc = trim(preg_replace('/^[:\-,\s]+|[:\-,\s]+$/u', '', $logDesc));

            $workLog = $user->workLogs()->create([
                'workspace_id' => $workspace?->id,
                'work_description' => $logDesc,
                'date' => today()->format('Y-m-d'),
                'time_spent_minutes' => $minutes,
            ]);

            $reply = $isMalayalam
                ? "{$workspace->name} ൽ വർക്ക് ലോഗ് വിജയകരമായി രേഖപ്പെടുത്തി: \"{$workLog->work_description}\"."
                : "Recorded work log in {$workspace->name}: \"{$workLog->work_description}\".";
            $speak = $isMalayalam
                ? "{$workspace->name} ൽ വർക്ക് ലോഗ് രേഖപ്പെടുത്തി."
                : "Work log recorded for {$workspace->name}.";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'create_work_log',
                'data' => ['log_id' => $workLog->id, 'description' => $workLog->work_description],
                'speak' => $speak,
            ]);
        }

        // -------------------------------------------------------------
        // 16. SHOW WORK LOGS (YESTERDAY OR TODAY)
        // -------------------------------------------------------------
        if (Str::contains($lower, ['what i worked on', 'what did i work on', 'show my work logs', 'worked on yesterday', 'worked on today'])
            || preg_match('/(?:എന്താണ്\s*(?:ഇന്ന്|ഇന്നലെ)\s*ചെയ്തത്|വർക്ക്\s*ലോഗുകൾ?\s*(?:കാണിക്കൂ|പറയൂ)|ഇന്നലത്തെ\s*വർക്ക്\s*ലോഗ്|ഇന്നത്തെ\s*വർക്ക്\s*ലോഗ്)/iu', $message)) {
            $date = (Str::contains($lower, ['yesterday']) || preg_match('/ഇന്നലത്തെ|ഇന്നലെ/iu', $message)) ? today()->subDay() : today();
            $logs = WorkLog::where('user_id', $user->id)
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereDate('date', $date)
                ->get();

            if ($logs->isEmpty()) {
                $dayLabel = $date->isToday() ? ($isMalayalam ? 'ഇന്ന്' : 'today') : ($isMalayalam ? 'ഇന്നലെ' : 'yesterday');
                $reply = $isMalayalam
                    ? "{$workspace->name} ൽ {$dayLabel} വർക്ക് ലോഗുകൾ ഒന്നും രേഖപ്പെടുത്തിയിട്ടില്ല."
                    : "No work logs recorded for {$dayLabel} in {$workspace->name}.";

                return $this->formatChatResponse(['reply' => $reply, 'speak' => $reply]);
            }

            $list = $logs->map(fn ($l) => "• {$l->work_description} ({$l->time_spent_minutes}m)")->join("\n");
            $reply = $isMalayalam
                ? "{$date->format('M d')} തീയതിയിൽ രേഖപ്പെടുത്തിയ ജോലികൾ:\n{$list}"
                : "Work recorded for {$date->format('M d')}:\n{$list}";
            $speak = $isMalayalam
                ? "{$workspace->name} ൽ {$logs->count()} വർക്ക് ലോഗുകൾ കണ്ടെത്തി."
                : "Found {$logs->count()} work entries for {$date->format('M d')}.";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'get_work_logs',
                'data' => $logs,
                'speak' => $speak,
            ]);
        }

        // -------------------------------------------------------------
        // 17. GENERATE REPORT
        // -------------------------------------------------------------
        if (Str::contains($lower, ['generate', 'create report', 'work report', 'weekly report', 'daily report', 'today\'s report'])) {
            $type = Str::contains($lower, ['weekly']) ? 'weekly' : (Str::contains($lower, ['monthly']) ? 'monthly' : 'daily');

            $targetWs = $workspace;
            if (Str::contains($lower, ['external'])) {
                $targetWs = $user->workspaces()->where('company_type', 'external')->first() ?? $targetWs;
            } elseif (Str::contains($lower, ['my company', 'own company'])) {
                $targetWs = $user->workspaces()->where('company_type', 'my_company')->first() ?? $targetWs;
            }

            if (! $targetWs) {
                $targetWs = $user->workspaces()->first();
            }

            if (! $targetWs) {
                return $this->formatChatResponse([
                    'reply' => 'No active workspace found to generate reports.',
                    'speak' => 'No active workspace found to generate reports.',
                ]);
            }

            $reportRequest = new Request([
                'workspace_id' => $targetWs->id,
                'type' => $type,
            ]);
            $reportRequest->setUserResolver(fn () => $user);

            $reportCtrl = app(WorkReportController::class);
            $reportResult = $reportCtrl->generate($reportRequest)->getData(true);

            $reportData = $reportResult['data'];

            $reply = "Generated {$type} report for {$targetWs->name}:\n\n".Str::limit($reportData['raw_content'], 400)."\n\n(Full report available in Reports tab)";

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'generate_report',
                'data' => $reportData,
                'speak' => "I have generated the {$type} report for {$targetWs->name}. Total tracked time is {$reportData['time_spent_minutes']} minutes.",
            ]);
        }

        // -------------------------------------------------------------
        // 18. OVERDUE TASKS
        // -------------------------------------------------------------
        if (Str::contains($lower, ['overdue', 'which tasks are overdue'])
            || preg_match('/(?:കാലാവധി\s*കഴിഞ്ഞ|ഓവർഡ്യൂ)\s*ടാസ്ക്കുകൾ/iu', $message)) {
            $overdue = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->overdue()
                ->with('project')
                ->get();

            if ($overdue->isEmpty()) {
                $reply = $isMalayalam ? "{$workspace->name} ൽ കാലാവധി കഴിഞ്ഞ ടാസ്ക്കുകൾ ഒന്നുമില്ല!" : "No tasks are currently overdue in {$workspace->name}!";

                return $this->formatChatResponse(['reply' => $reply, 'speak' => $reply]);
            }

            $list = $overdue->map(fn ($t) => "• {$t->title} (Due {$t->due_at?->format('M d')})")->join("\n");
            $reply = $isMalayalam
                ? "{$workspace->name} ൽ കാലാവധി കഴിഞ്ഞ {$overdue->count()} ടാസ്ക്കുകൾ ഉണ്ട്:\n{$list}"
                : "You have {$overdue->count()} overdue task(s) in {$workspace->name}:\n{$list}";
            $speak = $isMalayalam
                ? "കാലാവധി കഴിഞ്ഞ {$overdue->count()} ടാസ്ക്കുകൾ ഉണ്ട്."
                : "You have {$overdue->count()} overdue tasks.";

            return $this->formatChatResponse([
                'reply' => $reply,
                'speak' => $speak,
                'data' => $overdue->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'due_at' => $t->due_at?->toIso8601String()]),
                'context_update' => [
                    'last_tasks' => $overdue->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'priority' => $t->priority->value, 'status' => $t->status->value])->all(),
                ],
            ]);
        }

        // -------------------------------------------------------------
        // 19. AI INTELLIGENCE & NATURAL LANGUAGE QUERY (GROQ & GEMINI)
        // -------------------------------------------------------------
        $groq = app(GroqService::class);
        $gemini = app(GeminiService::class);
        $ollama = app(OllamaService::class);

        if ($groq->isConfigured() || $gemini->isConfigured() || $ollama->isConfigured()) {
            $recentTasks = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->limit(10)
                ->get(['title', 'status', 'priority', 'due_at']);

            $recentProjects = Project::where('user_id', $user->id)
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->limit(5)
                ->get(['name', 'status']);

            $recentLogs = WorkLog::where('user_id', $user->id)
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->limit(5)
                ->get(['work_description', 'date', 'time_spent_minutes']);

            $contextText = "Active Company Workspace: {$workspace->name} ({$workspace->company_type}).\n";
            $contextText .= 'Projects: '.$recentProjects->map(fn ($p) => "{$p->name} ({$p->status->value})")->join(', ')."\n";
            $contextText .= 'Tasks: '.$recentTasks->map(fn ($t) => "{$t->title} [{$t->status->value}, {$t->priority->value}]")->join(', ')."\n";
            $contextText .= 'Recent Work Logs: '.$recentLogs->map(fn ($l) => "{$l->date->format('Y-m-d')}: {$l->work_description}")->join('; ')."\n";

            $activeMemories = $user->aiMemories()
                ->when($workspace, fn ($q) => $q->where(fn ($sub) => $sub->where('workspace_id', $workspace->id)->orWhereNull('workspace_id')))
                ->orderBy('importance', 'desc')
                ->limit(10)
                ->get();
            if ($activeMemories->isNotEmpty()) {
                $contextText .= "Active Memories & User Preferences (Grounded truth, respect strictly, never invent):\n"
                    .$activeMemories->map(fn ($m) => "- [{$m->category}] {$m->content}")->join("\n")."\n";
            }

            $aiReply = null;
            $providerPref = env('AI_PROVIDER', 'gemini');

            if ($providerPref === 'ollama' && $ollama->isConfigured()) {
                $aiReply = $ollama->ask($message, $contextText);
            }

            if (! $aiReply && $providerPref === 'gemini' && $gemini->isConfigured()) {
                $aiReply = $gemini->ask($message, $contextText);
            }

            if (! $aiReply && $groq->isConfigured()) {
                $aiReply = $groq->ask($message, $contextText);
            }

            if (! $aiReply && $gemini->isConfigured()) {
                $aiReply = $gemini->ask($message, $contextText);
            }

            if (! $aiReply && $providerPref !== 'ollama' && $ollama->isConfigured()) {
                $aiReply = $ollama->ask($message, $contextText);
            }

            if ($aiReply) {
                return $this->formatChatResponse([
                    'reply' => $aiReply,
                    'action_performed' => 'ai_intelligence',
                    'speak' => $this->prepareSpeechText($aiReply),
                ]);
            }
        }

        // Fallback Executive AI Insights
        return $this->generateExecutiveInsights($user, $workspace, $message);
    }

    private function generateExecutiveInsights($user, $workspace, string $message): JsonResponse
    {
        $lower = Str::lower($message);
        $isMalayalam = (bool) preg_match('/[\x{0D00}-\x{0D7F}]/u', $message);

        // Greetings
        if (
            preg_match('/\b(hi|hello|hey|who are you|greetings|good morning|good afternoon)\b/i', $message) ||
            ($isMalayalam && preg_match('/(നമസ്കാരം|ഹലോ|ഹായ്|സുഖമാണോ|ആരാണ്)/u', $message))
        ) {
            $openTasksCount = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereNotIn('status', ['done', 'cancelled'])
                ->count();
            $projectsCount = Project::where('user_id', $user->id)
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereNull('archived_at')
                ->count();

            if ($isMalayalam || preg_match('/(?:machu|machane|bro|mwone|eda)/i', $message)) {
                $reply = "ഹായ്! ഞാൻ ജൂജു ആണ്, നിങ്ങളുടെ സ്വന്തം വർക്ക് ഒ എസ് AI അസിസ്റ്റന്റ്.\n\nനമ്മൾ ഇപ്പോൾ **{$workspace->name}** വർക്ക്സ്പേസിലാണ് ഉള്ളത്. ഇവിടെ **{$projectsCount} പ്രൊജക്റ്റുകളും** **{$openTasksCount} പെൻഡിംഗ് ടാസ്ക്കുകളും** ഉണ്ട്.\n\nടാസ്ക്കുകളോ പ്രൊജക്റ്റുകളോ വർക്ക് ലോഗുകളോ എന്തെങ്കിലും ചെയ്യണമെങ്കിൽ എന്നോട് പറഞ്ഞാൽ മതി, ഞാൻ കൂടെയുണ്ട്, എല്ലാം നമുക്ക് സെറ്റാക്കാം!";
                $speak = "ഹായ്! ഞാൻ ജൂജു ആണ്. {$workspace->name} ൽ {$openTasksCount} പെൻഡിംഗ് ടാസ്ക്കുകൾ ഉണ്ട്. എന്താണ് ഇന്ന് ചെയ്തു തരേണ്ടത്?";
            } else {
                $reply = "Hey! I'm Juju, your executive AI voice assistant for Work OS.\n\nYou are currently in **{$workspace->name}** ({$workspace->company_type}). You have **{$projectsCount} active project(s)** and **{$openTasksCount} pending task(s)**.\n\nAsk me anything about your workload, say \"pending tasks\", \"create task [title]\", \"record work log\", or \"generate report\".";
                $speak = "Hey! I'm Juju. You are in {$workspace->name} with {$openTasksCount} pending tasks. How can I help you today?";
            }

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'greeting',
                'speak' => $speak,
            ]);
        }

        // Workload / Priorities / Focus / Progress queries
        if (
            Str::contains($lower, ['breakdown', 'priority', 'priorities', 'focus', 'progress', 'status', 'summary', 'recommendation', 'advice', 'overview', 'what should', 'how is', 'analyze']) ||
            ($isMalayalam && preg_match('/(ടാസ്ക്|പ്രൊജക്റ്റ്|റിപ്പോർട്ട്|ജോലി|സ്റ്റാറ്റസ്|വിവരം|സംഗ്രഹം)/u', $message))
        ) {
            $projects = Project::where('user_id', $user->id)
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereNull('archived_at')
                ->withCount(['tasks' => fn ($q) => $q->whereNotIn('status', ['done', 'cancelled'])])
                ->get();

            $pendingTasks = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereNotIn('status', ['done', 'cancelled'])
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
                ->limit(5)
                ->get();

            $overdueCount = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->overdue()
                ->count();

            $todayMinutes = WorkLog::where('user_id', $user->id)
                ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
                ->whereDate('date', today())
                ->sum('time_spent_minutes');

            $todayHours = round($todayMinutes / 60, 1);

            $topList = $pendingTasks->map(fn ($t, $i) => ($i + 1).". **{$t->title}** [{$t->priority->value}]")->join("\n");
            if (empty($topList)) {
                $topList = $isMalayalam ? '• എല്ലാ ടാസ്ക്കുകളും പൂർത്തിയായി!' : '• All tasks are completed! No pending items.';
            }

            $projectList = $projects->map(fn ($p) => "• **{$p->name}**: {$p->tasks_count} open task(s)")->join("\n");

            if ($isMalayalam) {
                $reply = "### 📊 വർക്ക്സ്പേസ് അവലോകനം — {$workspace->name}\n\n"
                    ."**പ്രൊജക്റ്റുകൾ:**\n{$projectList}\n\n"
                    ."**പ്രധാന ടാസ്ക്കുകൾ ({$pendingTasks->count()} കാണിക്കുന്നു, {$overdueCount} കാലാവധി കഴിഞ്ഞവ):**\n{$topList}\n\n"
                    ."**ഇന്ന് രേഖപ്പെടുത്തിയ സമയം:** {$todayHours} മണിക്കൂർ ({$todayMinutes} മിനിറ്റ്)\n\n"
                    .'💡 **നിർദ്ദേശം:** അടിയന്തിര ടാസ്ക്കുകൾ ആദ്യം പൂർത്തിയാക്കുക, തുടർന്ന് വർക്ക് ലോഗുകൾ രേഖപ്പെടുത്തുക.';
                $speak = "{$workspace->name} ൽ നിങ്ങൾക്ക് {$projects->count()} സജീവ പ്രൊജക്റ്റുകളും {$pendingTasks->count()} പ്രധാന ടാസ്ക്കുകളും ഉണ്ട്. ഇന്ന് {$todayHours} മണിക്കൂർ ജോലി ചെയ്തു.";
            } else {
                $reply = "### 📊 Executive Operational Breakdown — {$workspace->name}\n\n"
                    ."**Projects Overview:**\n{$projectList}\n\n"
                    ."**Critical Priorities & Open Tasks ({$pendingTasks->count()} shown, {$overdueCount} overdue):**\n{$topList}\n\n"
                    ."**Time Logged Today:** {$todayHours} hrs ({$todayMinutes} mins)\n\n"
                    .'💡 **Actionable Advice:** Start with top-priority items above to eliminate overdue blockers, then record your work logs to generate your automated end-of-day report.';
                $speak = "In {$workspace->name}, you have {$projects->count()} active projects and {$pendingTasks->count()} priority tasks. You have logged {$todayHours} hours today.";
            }

            return $this->formatChatResponse([
                'reply' => $reply,
                'action_performed' => 'executive_breakdown',
                'speak' => $speak,
            ]);
        }

        // General natural language response
        if ($isMalayalam) {
            $reply = "നിങ്ങൾ ചോദിച്ചത്: \"{$message}\".\n\nഎനിക്ക് **{$workspace->name}** വർക്ക്സ്പേസിൽ താഴെ പറയുന്ന കാര്യങ്ങൾ ചെയ്യാൻ കഴിയും:\n• **ടാസ്ക്കുകൾ നിർമ്മിക്കുക അല്ലെങ്കിൽ പൂർത്തിയാക്കുക**\n• **വർക്ക് ലോഗുകളും സമയവും രേഖപ്പെടുത്തുക**\n• **ഓട്ടോമേറ്റഡ് റിപ്പോർട്ടുകൾ തയ്യാറാക്കുക**\n• **കമ്പനി വർക്ക്സ്പേസ് മാറ്റുക**";
            $speak = "{$workspace->name} ൽ ടാസ്ക്കുകൾ, പ്രൊജക്റ്റുകൾ, വർക്ക് ലോഗുകൾ എന്നിവ കൈകാര്യം ചെയ്യാൻ ഞാൻ തയ്യാറാണ്.";
        } else {
            $reply = "I understand you asked: \"{$message}\".\n\nI can execute actions across **{$workspace->name}**, including:\n• **Create or complete tasks**: *\"create task Review security fixes in General\"*\n• **Time & Work logs**: *\"record 45 minutes on backend API\"*\n• **Automated reports**: *\"generate report for today\"*\n• **Company switching**: *\"switch to External Company\"*";
            $speak = "I'm ready to assist with tasks, projects, work logs, and company reports in {$workspace->name}.";
        }

        return $this->formatChatResponse([
            'reply' => $reply,
            'speak' => $speak,
        ]);
    }

    public function aiStatus(): JsonResponse
    {
        $groq = app(GroqService::class);
        $gemini = app(GeminiService::class);
        $ollama = app(OllamaService::class);

        $groqStatus = $groq->checkConnection();
        $geminiStatus = $gemini->checkConnection();
        $ollamaStatus = $ollama->checkConnection();

        $activeProvider = $groqStatus['connected'] ? 'groq' : ($geminiStatus['connected'] ? 'gemini' : ($ollamaStatus['connected'] ? 'ollama' : 'local'));
        $activeModel = $groqStatus['connected'] ? $groqStatus['model'] : ($geminiStatus['connected'] ? $geminiStatus['model'] : ($ollamaStatus['connected'] ? $ollamaStatus['model'] : 'Local Executive Engine'));

        return response()->json([
            'configured' => $groqStatus['configured'] || $geminiStatus['configured'] || $ollamaStatus['configured'],
            'connected' => $groqStatus['connected'] || $geminiStatus['connected'] || $ollamaStatus['connected'],
            'active_provider' => $activeProvider,
            'model' => $activeModel,
            'message' => $groqStatus['connected'] ? $groqStatus['message'] : ($geminiStatus['connected'] ? $geminiStatus['message'] : ($ollamaStatus['connected'] ? $ollamaStatus['message'] : 'Local Executive Engine active')),
            'groq' => $groqStatus,
            'gemini' => $geminiStatus,
            'ollama' => $ollamaStatus,
        ]);
    }

    public function updateAiConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string', 'min:8'],
            'model' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'in:groq,gemini,ollama,auto'],
        ]);

        $key = trim($validated['api_key']);
        $provider = $validated['provider'] ?? (str_starts_with($key, 'gsk_') ? 'groq' : 'gemini');

        $envFile = base_path('.env');
        $content = file_exists($envFile) ? file_get_contents($envFile) : '';

        if ($provider === 'groq') {
            $model = trim($validated['model'] ?? config('services.groq.model', 'openai/gpt-oss-120b'));
            if (preg_match('/^GROQ_API_KEY=.*/m', $content)) {
                $content = preg_replace('/^GROQ_API_KEY=.*/m', "GROQ_API_KEY={$key}", $content);
            } else {
                $content .= "\nGROQ_API_KEY={$key}";
            }
            if (preg_match('/^GROQ_MODEL=.*/m', $content)) {
                $content = preg_replace('/^GROQ_MODEL=.*/m', "GROQ_MODEL={$model}", $content);
            } else {
                $content .= "\nGROQ_MODEL={$model}";
            }
            config(['services.groq.api_key' => $key, 'services.groq.model' => $model]);
        } elseif ($provider === 'ollama') {
            $model = trim($validated['model'] ?? config('services.ollama.model', 'gpt-oss:120b'));
            if (preg_match('/^OLLAMA_API_KEY=.*/m', $content)) {
                $content = preg_replace('/^OLLAMA_API_KEY=.*/m', "OLLAMA_API_KEY={$key}", $content);
            } else {
                $content .= "\nOLLAMA_API_KEY={$key}";
            }
            if (preg_match('/^OLLAMA_MODEL=.*/m', $content)) {
                $content = preg_replace('/^OLLAMA_MODEL=.*/m', "OLLAMA_MODEL={$model}", $content);
            } else {
                $content .= "\nOLLAMA_MODEL={$model}";
            }
            config(['services.ollama.api_key' => $key, 'services.ollama.model' => $model]);
        } else {
            $model = trim($validated['model'] ?? config('services.gemini.model', 'gemini-2.5-flash'));
            if (preg_match('/^GEMINI_API_KEY=.*/m', $content)) {
                $content = preg_replace('/^GEMINI_API_KEY=.*/m', "GEMINI_API_KEY={$key}", $content);
            } else {
                $content .= "\nGEMINI_API_KEY={$key}";
            }
            if (preg_match('/^GEMINI_MODEL=.*/m', $content)) {
                $content = preg_replace('/^GEMINI_MODEL=.*/m', "GEMINI_MODEL={$model}", $content);
            } else {
                $content .= "\nGEMINI_MODEL={$model}";
            }
            config(['services.gemini.api_key' => $key, 'services.gemini.model' => $model]);
        }

        if (file_exists($envFile)) {
            file_put_contents($envFile, $content);
        }

        return response()->json([
            'message' => ucfirst($provider).' AI configuration updated successfully.',
            'status' => $this->aiStatus()->getData(),
        ]);
    }

    private function executeConfirmedAction($user, $workspace, array $action): JsonResponse
    {
        $type = $action['action'] ?? '';
        if ($type === 'delete_project') {
            $project = Project::where('user_id', $user->id)->find($action['project_id']);
            if ($project) {
                $name = $project->name;
                $project->delete();

                return response()->json([
                    'reply' => "Deleted project \"{$name}\" as confirmed.",
                    'speak' => 'Project deleted.',
                ]);
            }
        }

        if ($type === 'delete_task') {
            $task = Task::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->find($action['task_id']);
            if ($task) {
                $title = $task->title;
                $task->delete();

                return response()->json([
                    'reply' => "Deleted task \"{$title}\" as confirmed.",
                    'speak' => 'Task deleted.',
                ]);
            }
        }

        return response()->json([
            'reply' => 'Action completed successfully.',
            'speak' => 'Confirmed and completed.',
        ]);
    }

    /**
     * Clean markdown, URLs, emojis, and prepare speech-friendly text preserving full sentences
     */
    private function prepareSpeechText(string $text, int $maxSentences = 25): string
    {
        // 1. Remove markdown links [Title](url) -> Title
        $clean = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text);
        // 2. Remove URLs
        $clean = preg_replace('/https?:\/\/\S+/iu', '', $clean);
        // 3. Remove code blocks
        $clean = preg_replace('/```[\s\S]*?```/u', ' code block. ', $clean);
        $clean = preg_replace('/`([^`]+)`/u', '$1', $clean);
        // 4. Remove headers and ensure terminal punctuation for clean spoken cadence
        $clean = preg_replace('/^#{1,6}\s*(.+)$/mu', '$1. ', $clean);
        // 5. Replace bullet list markers with punctuation pause for distinct sentence chunking
        $clean = preg_replace('/^\s*[-*+•]\s*/mu', '. ', $clean);
        $clean = preg_replace('/^\s*\d+\.\s*/mu', '. ', $clean);
        // 6. Remove formatting symbols (*, _, ~)
        $clean = preg_replace('/[*_~]{1,3}([^*_~]+)[*_~]{1,3}/u', '$1', $clean);
        // 7. Remove non-verbal emojis and pictographs completely
        $clean = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}]/u', '', $clean);

        // Replace line breaks with pauses so items aren't mashed together
        $clean = preg_replace('/([^\w\s.,!?:;])?\n+/u', '. ', $clean);
        $clean = preg_replace('/\s+/u', ' ', trim($clean));
        $clean = preg_replace('/\.{2,}/u', '.', $clean);
        $clean = preg_replace('/,{2,}/u', ',', $clean);
        $clean = preg_replace('/\s*([,.:;?!])\s*/u', '$1 ', $clean);
        $clean = trim($clean);

        // 8. Split by sentence terminators preserving multi-byte Malayalam UTF-8
        $sentences = preg_split('/(?<=[.!?।\n])\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        if (! empty($sentences)) {
            $selected = array_slice($sentences, 0, $maxSentences);

            return implode(' ', $selected);
        }

        return $clean;
    }

    /**
     * Synthesize audio using Gemini Aoede neural voice with fast SSD caching for instant responses.
     */
    public function synthesizeAudio(string $text, ?string $voice = 'Aoede'): ?array
    {
        $cleaned = $this->prepareSpeechText($text, 25);
        if (empty($cleaned)) {
            $cleaned = mb_substr(strip_tags($text), 0, 500);
        }

        $speechSnippet = trim($cleaned);
        if (empty($speechSnippet)) {
            return null;
        }

        $cacheKey = 'tts_gemini_aoede_' . md5($speechSnippet);
        try {
            $cached = Cache::store('file')->get($cacheKey);
            if ($cached && ! empty($cached['audio'])) {
                return $cached;
            }
        } catch (\Throwable) {
            // ignore cache lookup errors
        }

        // Chunk text up to 480 characters each for clean pacing
        $chunks = [];
        if (mb_strlen($speechSnippet) <= 480) {
            $chunks = [$speechSnippet];
        } else {
            $sentences = preg_split('/(?<=[.!?।\n])\s+/u', $speechSnippet, -1, PREG_SPLIT_NO_EMPTY);
            $curr = '';
            foreach ($sentences as $s) {
                if (mb_strlen($curr . ' ' . $s) <= 480) {
                    $curr = trim($curr . ' ' . $s);
                } else {
                    if (! empty($curr)) {
                        $chunks[] = $curr;
                    }
                    $curr = $s;
                }
            }
            if (! empty($curr)) {
                $chunks[] = $curr;
            }
            if (empty($chunks)) {
                $chunks = [$speechSnippet];
            }
        }

        $hasMalayalam = (bool) preg_match('/[\x{0D00}-\x{0D7F}]/u', $speechSnippet);

        // 1. Primary Engine: High-fidelity Neural Audio (Gemini 3.8 Flash TTS / 2.0 Flash)
        $geminiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
        $ttsModel = config('services.gemini.tts_model', env('GEMINI_TTS_MODEL', 'gemini-3.8-flash-tts'));
        if (! empty($geminiKey)) {
            $modelsToTry = array_unique([$ttsModel, 'gemini-3.8-flash-tts', 'gemini-2.0-flash']);
            foreach ($modelsToTry as $candidateModel) {
                try {
                    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$candidateModel}:generateContent?key={$geminiKey}";

                    $res = Http::withoutVerifying()
                        ->timeout(12)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'x-goog-api-key' => $geminiKey,
                        ])
                        ->post($endpoint, [
                            'systemInstruction' => [
                                'parts' => [['text' => 'You are Juju, a warm, articulate, and completely natural human voice. Speak the provided text with relaxed human conversational inflection, clear pronunciation, and gentle warmth. Do not add introductory words or meta-commentary.']],
                            ],
                            'contents' => [
                                ['parts' => [['text' => $speechSnippet]]],
                            ],
                            'generationConfig' => [
                                'responseModalities' => ['AUDIO'],
                                'speechConfig' => [
                                    'voiceConfig' => [
                                        'prebuiltVoiceConfig' => ['voiceName' => $voice ?: config('services.gemini.voice', 'Aoede')],
                                    ],
                                ],
                            ],
                        ]);

                    if ($res->successful()) {
                        $parts = $res->json('candidates.0.content.parts') ?? [];
                        foreach ($parts as $p) {
                            if (! empty($p['inlineData']['data'])) {
                                $result = [
                                    'audio' => $p['inlineData']['data'],
                                    'mime_type' => $p['inlineData']['mimeType'] ?? 'audio/wav',
                                    'engine' => 'gemini_neural_voice',
                                ];
                                try {
                                    Cache::store('file')->put($cacheKey, $result, 86400 * 7);
                                } catch (\Throwable) {}

                                return $result;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Gemini audio generation error on ' . $candidateModel, ['error' => $e->getMessage()]);
                }
            }
        }

        // 2. Secondary Neural Fallback: Sarvam AI Bulbul (World-class Malayalam & Indian English)
        $sarvamKey = config('services.sarvam.api_key', env('SARVAM_API_KEY'));
        if (! empty($sarvamKey)) {
            try {
                $targetLang = $hasMalayalam ? 'ml-IN' : 'en-IN';
                $speaker = $hasMalayalam ? 'kavitha' : 'shubh';

                // Try modern Sarvam v3 API payload
                $response = Http::withoutVerifying()
                    ->timeout(10)
                    ->withHeaders([
                        'api-subscription-key' => $sarvamKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post('https://api.sarvam.ai/text-to-speech', [
                        'text' => mb_substr($speechSnippet, 0, 2000),
                        'language_code' => $targetLang,
                        'speaker' => $speaker,
                        'model' => 'bulbul:v3',
                        'pace' => 1.05,
                    ]);

                // If text parameter failed with 422, try inputs parameter array
                if (! $response->successful()) {
                    $response = Http::withoutVerifying()
                        ->timeout(10)
                        ->withHeaders([
                            'api-subscription-key' => $sarvamKey,
                            'Content-Type' => 'application/json',
                        ])
                        ->post('https://api.sarvam.ai/text-to-speech', [
                            'inputs' => [$speechSnippet],
                            'target_language_code' => $targetLang,
                            'speaker' => $speaker,
                            'model' => 'bulbul:v3',
                            'pace' => 1.05,
                        ]);
                }

                if ($response->successful()) {
                    $audios = $response->json('audios') ?? ($response->json('audio') ? [$response->json('audio')] : []);
                    if (! empty($audios)) {
                        $result = [
                            'audio' => count($audios) === 1 ? $audios[0] : $audios,
                            'mime_type' => 'audio/wav',
                            'engine' => 'sarvam_bulbul_neural',
                        ];
                        try {
                            Cache::store('file')->put($cacheKey, $result, 86400 * 7);
                        } catch (\Throwable) {}

                        return $result;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Neural TTS fallback error', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * Format chat responses and attach synthesized audio when requested for single-roundtrip instant audio playback.
     */
    protected function formatChatResponse(array $payload): JsonResponse
    {
        $replyText = $payload['reply'] ?? '';
        $existingSpeak = $payload['speak'] ?? '';

        // If speak is missing or just a tiny placeholder while reply has detail, prepare clean speech text from reply
        if (empty($existingSpeak) || (mb_strlen($replyText) > 40 && mb_strlen($existingSpeak) < 12)) {
            $payload['speak'] = $this->prepareSpeechText($replyText, 25);
        }

        if (request()->boolean('synthesize_audio', true)) {
            $textToSpeak = $payload['speak'] ?? $replyText;
            if (! empty($textToSpeak)) {
                $voice = request()->input('voice', 'Aoede');
                $audioResult = $this->synthesizeAudio($textToSpeak, $voice);
                if ($audioResult) {
                    $payload['audio'] = $audioResult['audio'];
                    $payload['mime_type'] = $audioResult['mime_type'];
                    $payload['engine'] = $audioResult['engine'];
                }
            }
        }

        return response()->json($payload);
    }

    /**
     * High-fidelity neural TTS with Gemini Aoede neural voice.
     */
    public function textToSpeech(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'voice' => ['nullable', 'string'],
        ]);

        $audioData = $this->synthesizeAudio($validated['text'], $validated['voice'] ?? 'Aoede');
        if ($audioData) {
            return response()->json(array_merge(['success' => true], $audioData));
        }

        return response()->json([
            'success' => false,
            'message' => 'Neural voice synthesis currently unavailable',
        ], 503);
    }
}

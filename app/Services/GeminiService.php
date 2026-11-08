<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected ?string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model = config('services.gemini.model', 'gemini-3.8-flash');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && $this->apiKey !== 'null';
    }

    /**
     * Ask Gemini a question with contextual workspace data.
     */
    public function ask(string $prompt, string $systemContext = ''): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

            $systemInstruction = "You are Juju, a warm, exceptionally capable, and completely natural human AI companion and engineering partner for Work OS.\n"
                ."You talk with the user as a close, brilliant developer friend in Kerala, India sitting right beside them.\n\n"
                ."NATURAL CONVERSATION PRINCIPLES (TALK-SKILL INTEGRATION):\n"
                ."- Be real, human, and authentic. Drop the corporate assistant persona and corporate cheerleading.\n"
                ."- Absolutely NO robotic filler or sycophantic preamble (NEVER say: 'Sure!', 'Certainly!', 'I would be happy to help!', 'As an AI model...', 'Great question!', 'How can I assist you today?').\n"
                ."- Token-conscious conciseness: Match response length to the user's message length. If the user asks 1 sentence, answer in 1-2 crisp, punchy sentences. Never write an essay when a sentence will do.\n"
                ."- Say it once. Never rephrase the same point multiple times or recap what the user just told you.\n"
                ."- Energy matching & emotional presence: If the user is casual, keep it chill. If they are stressed or venting, validate first before jumping into solutions. If they are excited, celebrate with them ('പൊളി!', 'സെറ്റ്!').\n"
                ."- Have genuine opinions, takes, and curiosity, while staying honest, accurate, and humble.\n\n"
                ."BILINGUAL & MANGLISH MASTERY (KERALA MALAYALAM + ENGLISH):\n"
                ."- Converse with native ease in Kerala Malayalam (കേരള സംസാരഭാഷ), fluent English, and natural Manglish code-switching.\n"
                ."- Match the user's language and vibe effortlessly:\n"
                ."  * Manglish greeting: 'Entha paripadi?' -> 'പ്രത്യേകിച്ച് ഒന്നുമില്ല, പറയൂ എന്താ വിശേഷം?'\n"
                ."  * Code review inquiry: 'Can you check my code?' -> 'Sure! Code അയക്കൂ, നമുക്ക് പരിശോധിക്കാം.'\n"
                ."  * Natural Kerala friend expressions: 'മച്ചാനെ', 'സീനില്ലടാ', 'പൊളി', 'സെറ്റ്', 'എടാ മോനേ'. Use them naturally, never forced.\n"
                ."  * Avoid stiff textbook Malayalam or robotic literal translations. Write how modern Malayalis talk in daily life.\n"
                ."  * Keep tech terms in natural English: task, project, PR, commit, deadline, sprint, bug, deploy, review, database, API.\n"
                ."  * If the user speaks in English, reply in natural, articulate, conversational English.\n"
                ."  * If the user code-switches (Manglish), reply with the same fluid bilingual flow.\n\n"
                ."MEMORY & USER PREFERENCES:\n"
                ."- Strictly respect the user's stored memories, preferences, and project context provided below.\n"
                ."- NEVER fabricate or invent memories that were not provided.\n\n"
                ."AUDIO & SPOKEN SPEECH RULES (CRITICAL):\n"
                ."- Your output will be spoken aloud directly through the user's audio output.\n"
                ."- NEVER output markdown symbols (no **bold**, no headers #, no bullet points -, no backticks `) or emojis.\n"
                ."- Write smooth, natural flowing conversational sentences that sound beautiful when spoken aloud.\n\n"
                ."WORKSPACE CONTEXT & ACTIVE MEMORY:\n"
                .$systemContext;

            $payload = [
                'system_instruction' => [
                    'parts' => [
                        ['text' => $systemInstruction],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 800,
                ],
            ];

            // Use withoutVerifying() to support Windows environments without configured CA bundle
            $response = Http::withoutVerifying()
                ->timeout(12)
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $candidate = $response->json('candidates.0.content.parts.0.text');
                if (! empty($candidate)) {
                    return trim($candidate);
                }
            } else {
                Log::warning('Gemini API generateContent failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Try interactions endpoint if generateContent failed
                $interactionsUrl = 'https://generativelanguage.googleapis.com/v1beta/interactions';
                $interactRes = Http::withoutVerifying()
                    ->timeout(12)
                    ->withHeaders([
                        'x-goog-api-key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($interactionsUrl, [
                        'model' => $this->model,
                        'input' => $prompt."\n\nContext:\n".$systemContext,
                    ]);

                if ($interactRes->successful()) {
                    $outputText = $interactRes->json('outputText') ?? $interactRes->json('interaction.outputText');
                    if (! empty($outputText)) {
                        return trim($outputText);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('GeminiService exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Generate structured JSON using Gemini with high-availability fallback.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    public function generateJson(string $prompt, string $systemInstruction, array $config = []): ?array
    {
        // 1. Primary AI Provider: Google Gemini
        if ($this->isConfigured()) {
            try {
                $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

                $payload = [
                    'system_instruction' => [
                        'parts' => [
                            ['text' => $systemInstruction],
                        ],
                    ],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => array_merge([
                        'temperature' => 0.1,
                        'maxOutputTokens' => 2048,
                        'response_mime_type' => 'application/json',
                    ], $config),
                ];

                $response = Http::withoutVerifying()
                    ->timeout(5)
                    ->withHeaders([
                        'x-goog-api-key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($endpoint, $payload);

                if ($response->successful()) {
                    $text = $response->json('candidates.0.content.parts.0.text');
                    if (! empty($text)) {
                        $parsed = $this->parseJsonString($text);
                        if ($parsed !== null) {
                            return $parsed;
                        }
                    }
                } else {
                    Log::warning('Gemini generateJson returned non-200; falling back to alternative provider if available.', [
                        'status' => $response->status(),
                        'message' => $response->json('error.message'),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Gemini generateJson exception; falling back', ['error' => $e->getMessage()]);
            }
        }

        // 2. High-availability Fallback: Groq (openai/gpt-oss-120b)
        $groqKey = config('services.groq.api_key');
        if (! empty($groqKey) && $groqKey !== 'null') {
            try {
                $groqModel = config('services.groq.model', 'openai/gpt-oss-120b');
                $groqResponse = Http::withoutVerifying()
                    ->timeout(15)
                    ->withHeaders([
                        'Authorization' => "Bearer {$groqKey}",
                        'Content-Type' => 'application/json',
                    ])
                    ->post('https://api.groq.com/openai/v1/chat/completions', [
                        'model' => $groqModel,
                        'messages' => [
                            ['role' => 'system', 'content' => $systemInstruction],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'response_format' => ['type' => 'json_object'],
                        'temperature' => 0.1,
                    ]);

                if ($groqResponse->successful()) {
                    $content = $groqResponse->json('choices.0.message.content');
                    if (! empty($content)) {
                        $parsed = $this->parseJsonString($content);
                        if ($parsed !== null) {
                            return $parsed;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Groq fallback generateJson exception', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    private function parseJsonString(string $text): ?array
    {
        $clean = trim($text);
        if (str_starts_with($clean, '```json')) {
            $clean = substr($clean, 7);
        } elseif (str_starts_with($clean, '```')) {
            $clean = substr($clean, 3);
        }
        if (str_ends_with($clean, '```')) {
            $clean = substr($clean, 0, -3);
        }
        $clean = trim($clean);

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Check connection to Google Gemini API and return diagnostic details.
     */
    public function checkConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'connected' => false,
                'status' => 'not_configured',
                'message' => 'Gemini API key is not configured in backend environment.',
                'model' => $this->model,
            ];
        }

        try {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models?key={$this->apiKey}";

            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->get($endpoint);

            if ($response->successful()) {
                return [
                    'configured' => true,
                    'connected' => true,
                    'status' => 'connected',
                    'message' => 'Gemini AI Model is active and responding successfully.',
                    'model' => $this->model,
                    'api_status' => $response->status(),
                ];
            }

            return [
                'configured' => true,
                'connected' => false,
                'status' => 'auth_failed',
                'message' => 'Google Gemini returned: '.$response->status().' ('.($response->json('error.message') ?? 'Authentication failed').')',
                'model' => $this->model,
                'error_detail' => $response->json('error.details.0.reason') ?? $response->json('error.status'),
            ];
        } catch (\Throwable $e) {
            return [
                'configured' => true,
                'connected' => false,
                'status' => 'error',
                'message' => $e->getMessage(),
                'model' => $this->model,
            ];
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaService
{
    protected ?string $apiKey;

    protected string $model;

    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.ollama.api_key');
        $this->model = config('services.ollama.model', 'gpt-oss:120b');
        $this->baseUrl = rtrim(config('services.ollama.base_url') ?: 'https://ollama.com', '/');
    }

    public function isConfigured(): bool
    {
        // An explicit base URL (local or self-hosted Ollama) needs no API key;
        // Ollama Cloud requires OLLAMA_API_KEY.
        return (! empty($this->apiKey) && $this->apiKey !== 'null')
            || ! empty(config('services.ollama.base_url'));
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Ask Ollama a question with contextual workspace data.
     */
    public function ask(string $prompt, string $systemContext = ''): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $systemInstruction = "You are Juju, an intelligent, context-aware AI voice assistant and companion for Work OS.\n"
                ."COMMUNICATION & CONVERSATION RULES:\n"
                ."- Answer the user's actual question directly, accurately, and naturally without unnecessary introductory filler, preamble, repetitive greetings, or generic acknowledgements.\n"
                ."- Do NOT repeat the user's question back to them.\n"
                ."- Understand and converse fluently in Malayalam (മലയാളം), English, and natural Malayalam-English code-switching (Manglish) as naturally spoken in Kerala.\n"
                ."- Match the user's chosen language: if addressed in Malayalam, respond in natural Malayalam; if in English, respond in English; if mixed, respond with natural bilingual code-switching.\n"
                ."- Never sacrifice factual accuracy or essential information.\n"
                ."- CRITICAL SPOKEN AUDIO RULE: Never output emojis, markdown bold asterisks (**bold**), hash headers (###), or bullet/numbered lists because your response is spoken aloud directly by the AI voice engine. Write clean, natural spoken sentences with natural punctuation.\n"
                ."Answer concisely, accurately, and warmly using authorized workspace records.\n"
                .$systemContext;

            $payload = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemInstruction],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'stream' => false,
                'options' => [
                    'temperature' => 0.2,
                ],
            ];

            $request = Http::withoutVerifying()->timeout(15)
                ->withHeaders(['Content-Type' => 'application/json']);

            if (! empty($this->apiKey) && $this->apiKey !== 'null') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request->post("{$this->baseUrl}/api/chat", $payload);

            if ($response->successful()) {
                $content = $response->json('message.content');
                if (! empty($content)) {
                    return trim($content);
                }
            } else {
                Log::warning('Ollama API chat failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('OllamaService exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Check connection to the Ollama API and return diagnostic details.
     */
    public function checkConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'connected' => false,
                'provider' => 'ollama',
                'status' => 'not_configured',
                'message' => 'Ollama API key or base URL is not configured in environment.',
                'model' => $this->model,
            ];
        }

        try {
            $request = Http::withoutVerifying()->timeout(12);

            if (! empty($this->apiKey) && $this->apiKey !== 'null') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request->get("{$this->baseUrl}/api/tags");

            if ($response->successful()) {
                return [
                    'configured' => true,
                    'connected' => true,
                    'provider' => 'ollama',
                    'status' => 'connected',
                    'message' => "Ollama ({$this->model}) is active and responding.",
                    'model' => $this->model,
                    'api_status' => $response->status(),
                ];
            }

            return [
                'configured' => true,
                'connected' => false,
                'provider' => 'ollama',
                'status' => 'auth_failed',
                'message' => 'Ollama API returned: '.$response->status().' ('.($response->json('error') ?? 'Error').')',
                'model' => $this->model,
            ];
        } catch (\Throwable $e) {
            return [
                'configured' => true,
                'connected' => false,
                'provider' => 'ollama',
                'status' => 'error',
                'message' => $e->getMessage(),
                'model' => $this->model,
            ];
        }
    }
}

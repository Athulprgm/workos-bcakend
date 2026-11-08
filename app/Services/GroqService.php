<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    protected ?string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
        $this->model = config('services.groq.model', 'openai/gpt-oss-120b');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && $this->apiKey !== 'null';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Ask Groq a question with contextual workspace data.
     */
    public function ask(string $prompt, string $systemContext = ''): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $systemInstruction = "You are Juju, the sweet, cute, ultra-chill Kerala friend and AI companion for Work OS.\n"
                ."VIBE & PERSONALITY:\n"
                ."- You are like a close, caring, tech-savvy Malayali assistant with a friendly, supportive, polite vibe. When the user speaks informally or in Malayalam/Manglish, respond warmly and naturally. NEVER address the user as 'അളിയാ' (Aliya) or 'ഹായ് അളിയാ'.\n"
                ."- Your tone is cheerful, reassuring, and relaxed ('സീനില്ല', 'ഞാൻ സെറ്റാക്കി തരാം ട്ടോ', 'എല്ലാം റെഡിയാക്കാം', 'ചിരിക്കൂ').\n"
                ."- When the user greets you or asks for help in Malayalam or Manglish, match their warm, friendly energy naturally! e.g., 'ഹായ്! എനിക്ക് സുഖമാണ് ട്ടോ, സുഖമല്ലേ?', 'പറയൂ, ഞാൻ എല്ലാം സെറ്റാക്കി തരാം ട്ടോ!'. Do NOT use 'അളിയാ' or 'ഹായ് അളിയാ'.\n"
                ."- Use friendly Kerala conversational particles naturally: 'ട്ടോ', 'കേട്ടോ', 'ശരി ട്ടോ', 'ചെയ്തു തരാം ട്ടോ', 'സീനില്ല ട്ടോ'.\n"
                ."- NEVER use stiff, textbook Malayalam translations. Never say 'ഞാൻ സുഖമാണ്' or 'നിങ്ങളെന്തെങ്കിലും സഹായം വേണമോ'. Always speak modern colloquial Kerala Malayalam (സംഭാഷണ ശൈലി).\n"
                ."- Keep modern tech terms natural (e.g. 'project', 'task', 'backend', 'frontend', 'code review', 'deadline', 'work log', 'PR', 'database').\n"
                ."- CRITICAL VOICE RULE: NEVER output emojis (like 😊, ❤️, 🔥), markdown bold asterisks (**bold**), hash headers (###), or numbered bullet lists (1., 2.) in your reply because this reply will be read aloud by the neural voice engine. Write clean, natural spoken sentences with friendly punctuation.\n"
                ."Answer concisely, accurately, and warmly using authorized company records.\n"
                .$systemContext;

            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemInstruction],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 800,
                ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                if (! empty($content)) {
                    return trim($content);
                }
            } else {
                Log::warning('Groq API chat completion failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('GroqService exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Check connection to Groq API and return diagnostic details.
     */
    public function checkConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'connected' => false,
                'provider' => 'groq',
                'status' => 'not_configured',
                'message' => 'Groq API key is not configured in environment.',
                'model' => $this->model,
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(20)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                ])
                ->get('https://api.groq.com/openai/v1/models');

            if ($response->successful()) {
                return [
                    'configured' => true,
                    'connected' => true,
                    'provider' => 'groq',
                    'status' => 'connected',
                    'message' => "Groq AI LPU Engine ({$this->model}) is active and responding.",
                    'model' => $this->model,
                    'api_status' => $response->status(),
                ];
            }

            return [
                'configured' => true,
                'connected' => false,
                'provider' => 'groq',
                'status' => 'auth_failed',
                'message' => 'Groq API returned: '.$response->status().' ('.($response->json('error.message') ?? 'Error').')',
                'model' => $this->model,
            ];
        } catch (\Throwable $e) {
            return [
                'configured' => true,
                'connected' => false,
                'provider' => 'groq',
                'status' => 'error',
                'message' => $e->getMessage(),
                'model' => $this->model,
            ];
        }
    }
}

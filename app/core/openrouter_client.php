<?php

declare(strict_types=1);

namespace SupaBein;

class OpenRouterClient
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    // Some models are routed through providers that pre-authorize spend against
    // max_tokens regardless of actual usage; cap these to what the account can afford.
    private const MAX_TOKENS_OVERRIDES = [
        'moonshotai/kimi-k2' => 12000,
    ];

    private array $lastUsage = [];
    private string $lastRawText = '';

    public function __construct(
        private string $apiKey,
        private string $model = 'google/gemini-2.5-flash'
    ) {}

    public function getLastUsage(): array
    {
        return $this->lastUsage;
    }

    /** The raw model reply text, populated even when it failed to parse as JSON. */
    public function getLastRawText(): string
    {
        return $this->lastRawText;
    }

    public function generateJson(string $systemPrompt, string $userPrompt): array
    {
        return $this->call([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ]);
    }

    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt): array
    {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $turn) {
            if (!isset($turn['role'], $turn['text'])) continue;
            // Gemini history uses 'model'; OpenAI-compatible API uses 'assistant'
            $messages[] = [
                'role'    => ($turn['role'] === 'model' ? 'assistant' : 'user'),
                'content' => $turn['text'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];
        return $this->call($messages);
    }

    private function call(array $messages): array
    {
        $body    = [
            'model'      => $this->model,
            'messages'   => $messages,
            // Not all routed providers support response_format=json_object (some reject
            // the request outright, others silently drop the final answer into a
            // "reasoning" field). We rely on the system prompt + ai_lenient_json() instead.
            'max_tokens' => self::MAX_TOKENS_OVERRIDES[$this->model] ?? 65536,
        ];
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Several free-tier models route through a single backing provider with no
        // OpenRouter-side failover, so a transient upstream hiccup surfaces as an
        // outright request failure. Retry rate limits and 5xx before giving up.
        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'supabein'),
                ],
                CURLOPT_TIMEOUT        => 420,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException('OpenRouter network error: ' . $curlErr);
            }

            if ($httpCode !== 200) {
                $errBody = json_decode($response, true);
                $msg = $errBody['error']['message'] ?? ('HTTP ' . $httpCode);
                $lastError = new \RuntimeException('OpenRouter error: ' . $msg);
                if (($httpCode === 429 || $httpCode >= 500) && $attempt < 3) {
                    sleep($attempt * 2);
                    continue;
                }
                throw $lastError;
            }

            $envelope = json_decode($response, true);
            $text     = $envelope['choices'][0]['message']['content'] ?? null;

            $raw = $envelope['usage'] ?? [];
            $this->lastUsage = [
                'prompt_tokens'     => (int)($raw['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($raw['completion_tokens'] ?? 0),
                'total_tokens'      => (int)($raw['total_tokens'] ?? 0),
            ];

            if ($text === null) {
                throw new \RuntimeException('OpenRouter returned no content in response');
            }
            $this->lastRawText = $text;

            $plan = json_decode($text, true);
            if (!is_array($plan)) {
                $plan = ai_lenient_json($text);
            }
            if (!is_array($plan)) {
                throw new \RuntimeException('OpenRouter response was not valid JSON: ' . substr($text, 0, 200));
            }

            return $plan;
        }

        throw $lastError;
    }
}

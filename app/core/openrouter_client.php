<?php

declare(strict_types=1);

namespace SupaBein;

class OpenRouterClient
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    private array $lastUsage = [];

    public function __construct(
        private string $apiKey,
        private string $model = 'google/gemini-2.5-flash'
    ) {}

    public function getLastUsage(): array
    {
        return $this->lastUsage;
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
            'model'           => $this->model,
            'messages'        => $messages,
            'response_format' => ['type' => 'json_object'],
            'max_tokens'      => 16384,
        ];
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

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
            CURLOPT_TIMEOUT        => 90,
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
            $body = json_decode($response, true);
            $msg  = $body['error']['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('OpenRouter error: ' . $msg);
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

        // Strip markdown fences that some models add despite json_object mode
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/m', '', $text);

        $plan = json_decode(trim($text), true);
        if (!is_array($plan)) {
            throw new \RuntimeException('OpenRouter response was not valid JSON: ' . substr($text, 0, 200));
        }

        return $plan;
    }
}

<?php

declare(strict_types=1);

namespace SupaBein;

class NvidiaClient
{
    private const ENDPOINT = 'https://integrate.api.nvidia.com/v1/chat/completions';

    private array $lastUsage = [];

    public function __construct(
        private string $apiKey,
        private string $model = 'qwen/qwen3.5-122b-a10b'
    ) {}

    public function getLastUsage(): array
    {
        return $this->lastUsage;
    }

    public function generateJson(string $systemPrompt, string $userPrompt): array
    {
        return $this->call([
            ['role' => 'system', 'content' => self::textContent($systemPrompt)],
            ['role' => 'user',   'content' => self::textContent($userPrompt)],
        ]);
    }

    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt): array
    {
        $messages = [['role' => 'system', 'content' => self::textContent($systemPrompt)]];
        foreach ($history as $turn) {
            if (!isset($turn['role'], $turn['text'])) continue;
            $messages[] = [
                'role'    => ($turn['role'] === 'model' ? 'assistant' : 'user'),
                'content' => self::textContent($turn['text']),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => self::textContent($userPrompt)];
        return $this->call($messages);
    }

    private static function textContent(string $text): array
    {
        return [['type' => 'text', 'text' => $text]];
    }

    private function call(array $messages): array
    {
        $body    = [
            'model'                 => $this->model,
            'messages'              => $messages,
            'max_tokens'            => 8192,
            'stream'                => false,
            'chat_template_kwargs'  => ['enable_thinking' => false],
        ];
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 420,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('NVIDIA network error: ' . $curlErr);
        }

        if ($httpCode !== 200) {
            $errBody = json_decode($response, true);
            $msg = $errBody['error']['message']
                ?? $errBody['message']
                ?? ('HTTP ' . $httpCode . ': ' . substr($response, 0, 300));
            throw new \RuntimeException('NVIDIA error: ' . $msg);
        }

        $envelope = json_decode($response, true);
        $msg      = $envelope['choices'][0]['message'] ?? [];
        // Some reasoning models put output in reasoning_content; prefer content
        $text     = $msg['content'] ?? $msg['reasoning_content'] ?? null;

        $raw = $envelope['usage'] ?? [];
        $this->lastUsage = [
            'prompt_tokens'     => (int)($raw['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($raw['completion_tokens'] ?? 0),
            'total_tokens'      => (int)($raw['total_tokens'] ?? 0),
        ];

        if ($text === null || trim($text) === '') {
            throw new \RuntimeException('NVIDIA returned no content in response');
        }

        // Strip <think>...</think> blocks that reasoning models prepend
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
        $text = trim($text);

        $plan = json_decode($text, true);
        if (!is_array($plan)) {
            $plan = ai_lenient_json($text);
        }
        if (!is_array($plan)) {
            throw new \RuntimeException('NVIDIA response was not valid JSON: ' . substr($text, 0, 200));
        }

        return $plan;
    }
}

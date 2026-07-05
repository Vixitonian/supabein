<?php

declare(strict_types=1);

namespace SupaBein;

class NvidiaClient
{
    private const ENDPOINT = 'https://integrate.api.nvidia.com/v1/chat/completions';
    // Deliberately generous starting point — the real per-model ceiling is
    // auto-discovered from the API's own rejection message on first use
    // (see MaxTokensProbe) rather than hand-maintained here.
    private const MAX_TOKENS_DEFAULT = 100000;

    private array $lastUsage = [];
    private string $lastRawText = '';

    public function __construct(
        private string $apiKey,
        private string $model = 'qwen/qwen3.5-122b-a10b'
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
        $probeKey  = 'nvidia:' . $this->model;
        $maxTokens = MaxTokensProbe::initial($probeKey, self::MAX_TOKENS_DEFAULT);

        $lastError = null;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $body = [
                'model'                => $this->model,
                'messages'             => $messages,
                'max_tokens'           => $maxTokens,
                'stream'               => false,
                'chat_template_kwargs' => ['enable_thinking' => false],
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
                    ?? $errBody['detail']
                    ?? $errBody['message']
                    ?? ('HTTP ' . $httpCode . ': ' . substr($response, 0, 300));
                $msg = is_string($msg) ? $msg : json_encode($msg);
                $lastError = new \RuntimeException('NVIDIA error: ' . $msg);

                // A too-high max_tokens is self-correcting: the error almost
                // always states the real ceiling for this model, so shrink to
                // it and retry — same mechanism as AnthropicClient/OpenRouterClient.
                // Not gated on a specific HTTP status — extractLimit() itself
                // is the real safety gate (only ever returns a strictly lower
                // value than what was sent).
                if ($attempt < 4 && stripos($msg, 'token') !== false) {
                    $corrected = MaxTokensProbe::extractLimit($msg, $maxTokens);
                    if ($corrected !== null) {
                        $maxTokens = $corrected;
                        MaxTokensProbe::remember($probeKey, $maxTokens);
                        continue;
                    }
                }
                // Retry on DEGRADED (transient backend failure), 5xx, or 429
                // (rate limit) — without this, a tight agentic loop hammering
                // this client (many calls in quick succession) hits 429, the
                // caller's own catch-and-retry fires immediately with no
                // delay, hits 429 again instantly, and burns its entire turn
                // budget on rate-limit errors in milliseconds instead of ever
                // getting a real generation through.
                if (($httpCode >= 500 || $httpCode === 429 || str_contains($msg, 'DEGRADED')) && $attempt < 4) {
                    sleep($attempt * 2);
                    continue;
                }
                throw $lastError;
            }

            MaxTokensProbe::remember($probeKey, $maxTokens);

            $envelope     = json_decode($response, true);
            $choice       = $envelope['choices'][0] ?? [];
            $msg          = $choice['message'] ?? [];
            $text         = $msg['content'] ?? $msg['reasoning_content'] ?? null;
            $finishReason = $choice['finish_reason'] ?? null;

            $raw = $envelope['usage'] ?? [];
            $this->lastUsage = [
                'prompt_tokens'     => (int)($raw['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($raw['completion_tokens'] ?? 0),
                'total_tokens'      => (int)($raw['total_tokens'] ?? 0),
            ];

            if ($text === null || trim($text) === '') {
                throw new \RuntimeException('NVIDIA returned no content in response');
            }

            if ($finishReason === 'length') {
                throw new \RuntimeException('NVIDIA output was cut off (too long). Try a simpler description or use Gemini for large builds.');
            }

            // Strip <think>...</think> blocks that reasoning models prepend
            $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
            $text = trim($text);
            $this->lastRawText = $text;

            $plan = json_decode($text, true);
            if (!is_array($plan)) {
                $plan = ai_lenient_json($text);
            }
            if (!is_array($plan)) {
                throw new \RuntimeException('NVIDIA response was not valid JSON: ' . substr($text, 0, 200));
            }

            return $plan;
        }

        throw $lastError;
    }
}

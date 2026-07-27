<?php

declare(strict_types=1);

namespace SupaBein;

// Zhipu / BigModel (open.bigmodel.cn), OpenAI-compatible chat-completions
// endpoint. Both GLM models here are "thinking" models -- every reply
// carries a separate `reasoning_content` field ahead of the real `content`,
// and glm-4.7-flash in particular burns a LOT of budget on it: live-tested
// against a trivial 2-field JSON request, it used ~900 tokens of pure
// reasoning before ever emitting content, and finish_reason came back
// "length" (content empty) at anything under a few thousand max_tokens.
// glm-4.5-flash is far cheaper (~100 tokens total for the same prompt) and
// is the default for exactly that reason.
class ZhipuClient
{
    private const ENDPOINT = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
    // Comfortably below both models' real ceilings (live-verified via the
    // API's own "max_tokens out of range" error: glm-4.5-flash allows up to
    // 98304, glm-4.7-flash up to 131072) while leaving enough headroom for
    // glm-4.7-flash's heavy reasoning trace on a real, multi-thousand-token
    // flyer-planner prompt, not just the trivial one this was measured on.
    private const MAX_TOKENS_DEFAULT = 16000;

    private array $lastUsage = [];
    private string $lastRawText = '';

    public function __construct(
        private string $apiKey,
        private string $model = 'glm-4.5-flash',
        private int $timeoutSeconds = 420
    ) {}

    public function getLastUsage(): array
    {
        return $this->lastUsage;
    }

    public function getLastRawText(): string
    {
        return $this->lastRawText;
    }

    public function generateJson(string $systemPrompt, string $userPrompt, array $attachments = [], bool $jsonMode = true): array
    {
        return $this->call([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ], $jsonMode);
    }

    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt, array $attachments = [], bool $jsonMode = true): array
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
        return $this->call($messages, $jsonMode);
    }

    private function call(array $messages, bool $jsonMode = true): array
    {
        $probeKey  = 'zhipu:' . $this->model;
        $maxTokens = MaxTokensProbe::initial($probeKey, self::MAX_TOKENS_DEFAULT);

        $lastError = null;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $body = [
                'model'           => $this->model,
                'messages'        => $messages,
                'max_tokens'      => $maxTokens,
                'stream'          => false,
            ];
            // Conversational chat callers (Catalog::callAiAssistant(), which
            // already tolerates a plain-text reply) pass jsonMode: false --
            // no reason to force JSON syntax on a normal chat turn.
            if ($jsonMode) {
                $body['response_format'] = ['type' => 'json_object'];
            }
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $responseHeaders = [];
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
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HEADERFUNCTION => function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                    $parts = explode(':', $headerLine, 2);
                    if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    return strlen($headerLine);
                },
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException('Zhipu network error: ' . $curlErr);
            }

            if ($httpCode !== 200) {
                $errBody = json_decode($response, true);
                $msg = $errBody['error']['message'] ?? ('HTTP ' . $httpCode . ': ' . substr($response, 0, 300));
                $msg = is_string($msg) ? $msg : json_encode($msg);
                $lastError = new \RuntimeException('Zhipu error: ' . $msg);

                // Zhipu's own "max_tokens out of range" message is in
                // Chinese ("限制数值范围[1,98304]") and doesn't match
                // MaxTokensProbe's English-oriented patterns -- parse its
                // own [min,max] range directly rather than teaching the
                // shared probe a provider-specific format.
                if ($attempt < 4 && preg_match('/\[\s*\d+\s*,\s*(\d+)\s*\]/', $msg, $m)) {
                    $corrected = (int)$m[1];
                    if ($corrected > 0 && $corrected < $maxTokens) {
                        $maxTokens = $corrected;
                        MaxTokensProbe::remember($probeKey, $maxTokens);
                        continue;
                    }
                }
                if (($httpCode >= 500 || $httpCode === 429) && $attempt < 4) {
                    $retryAfter = isset($responseHeaders['retry-after']) ? (float)$responseHeaders['retry-after'] : null;
                    $wait = $retryAfter !== null ? min(60.0, max(1.0, $retryAfter)) : ($attempt * 2);
                    sleep((int)ceil($wait));
                    continue;
                }
                throw $lastError;
            }

            MaxTokensProbe::remember($probeKey, $maxTokens);

            $envelope     = json_decode($response, true);
            $choice       = $envelope['choices'][0] ?? [];
            $msg          = $choice['message'] ?? [];
            $text         = $msg['content'] ?? null;
            $finishReason = $choice['finish_reason'] ?? null;

            $raw = $envelope['usage'] ?? [];
            $this->lastUsage = [
                'prompt_tokens'     => (int)($raw['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($raw['completion_tokens'] ?? 0),
                'total_tokens'      => (int)($raw['total_tokens'] ?? 0),
            ];

            // The reasoning trace ran the whole max_tokens budget out before
            // any real content came back -- same failure shape as Groq's
            // "cut off" case, just far more likely to happen here given how
            // reasoning-heavy these models are. Bumping the budget and
            // retrying in place (rather than surfacing immediately) gives a
            // real chance at a complete answer instead of failing a request
            // that would have succeeded with a bit more room.
            if (($text === null || trim($text) === '') && $finishReason === 'length') {
                if ($attempt < 4 && $maxTokens < 98000) {
                    $maxTokens = min(98000, $maxTokens * 2);
                    MaxTokensProbe::remember($probeKey, $maxTokens);
                    continue;
                }
                throw new \RuntimeException('Zhipu output was cut off by its own reasoning before producing a reply. Try a simpler prompt or a different model.');
            }

            if ($text === null || trim($text) === '') {
                throw new \RuntimeException('Zhipu returned no content in response');
            }

            $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
            $text = trim($text);
            $this->lastRawText = $text;

            $plan = json_decode($text, true);
            if (!is_array($plan)) {
                $plan = ai_lenient_json($text);
            }
            if (!is_array($plan)) {
                throw new \RuntimeException('Zhipu response was not valid JSON: ' . substr($text, 0, 200));
            }

            return $plan;
        }

        throw $lastError;
    }
}

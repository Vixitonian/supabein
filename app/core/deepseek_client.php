<?php

declare(strict_types=1);

namespace SupaBein;

// DeepSeek's own API (api.deepseek.com), OpenAI-compatible chat-completions
// endpoint. Model existence was confirmed via GET /models (doesn't require
// balance); an actual completion could NOT be live-tested end-to-end at
// wiring time -- this account's DeepSeek balance is $0, so every real call
// returns 402 "Insufficient Balance" regardless of model. That's a billing
// state, not a wiring problem (confirmed by comparing against a garbage key,
// which correctly returns a 401 auth error instead) -- this client starts
// working the moment the account is funded, no code change needed.
class DeepSeekClient
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';
    private const MAX_TOKENS_DEFAULT = 8000;

    private array $lastUsage = [];
    private string $lastRawText = '';

    public function __construct(
        private string $apiKey,
        private string $model = 'deepseek-v4-flash',
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

    // See ZhipuClient's matching comment (task #198) -- $onRetry is optional
    // and additive, surfacing this client's own internal retry loop to
    // whichever pipeline stage called it, instead of leaving the live
    // progress UI silent for however long the retries take.
    public function generateJson(string $systemPrompt, string $userPrompt, array $attachments = [], bool $jsonMode = true, ?callable $onRetry = null): array
    {
        return $this->call([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ], $jsonMode, $onRetry);
    }

    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt, array $attachments = [], bool $jsonMode = true, ?callable $onRetry = null): array
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
        return $this->call($messages, $jsonMode, $onRetry);
    }

    private function call(array $messages, bool $jsonMode = true, ?callable $onRetry = null): array
    {
        $probeKey  = 'deepseek:' . $this->model;
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
                throw new \RuntimeException('DeepSeek network error: ' . $curlErr);
            }

            if ($httpCode !== 200) {
                $errBody = json_decode($response, true);
                $msg = $errBody['error']['message'] ?? ('HTTP ' . $httpCode . ': ' . substr($response, 0, 300));
                $msg = is_string($msg) ? $msg : json_encode($msg);
                $lastError = new \RuntimeException('DeepSeek error: ' . $msg);

                // "Insufficient Balance" is a billing state, not a transient
                // failure -- retrying won't help, and ai_is_unrecoverable_
                // provider_error() already recognizes "insufficient" as a
                // reason to jump straight to the next candidate in a
                // FallbackAiClient chain rather than burning the retry loop
                // here. Same posture as Groq's daily-quota 429 case.
                if ($httpCode === 402) {
                    throw $lastError;
                }

                if ($attempt < 4 && stripos($msg, 'max_tokens') !== false) {
                    $corrected = MaxTokensProbe::extractLimit($msg, $maxTokens);
                    if ($corrected !== null) {
                        $maxTokens = $corrected;
                        MaxTokensProbe::remember($probeKey, $maxTokens);
                        if ($onRetry) $onRetry($attempt, 4, 'Adjusting token budget and retrying…', 0.0);
                        continue;
                    }
                }
                if (($httpCode >= 500 || $httpCode === 429) && $attempt < 4) {
                    $retryAfter = isset($responseHeaders['retry-after']) ? (float)$responseHeaders['retry-after'] : null;
                    $wait = $retryAfter !== null ? min(60.0, max(1.0, $retryAfter)) : ($attempt * 2);
                    if ($onRetry) $onRetry($attempt, 4, ($httpCode === 429 ? 'Rate limited by the AI provider' : 'AI provider server error') . " — retrying in {$wait}s…", $wait);
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

            if ($text === null || trim($text) === '') {
                throw new \RuntimeException('DeepSeek returned no content in response');
            }
            // finish_reason "length" means the model got cut off mid-response --
            // bump the budget and retry in place rather than immediately
            // failing, same self-correcting pattern as the max_tokens-out-of-
            // range case above. 200000 is a deliberately generous ceiling, not
            // a per-model limit -- if it's too high for this model, the
            // out-of-range handler above already corrects back down on the
            // next attempt.
            if ($finishReason === 'length') {
                if ($attempt < 4 && $maxTokens < 200000) {
                    $maxTokens = min(200000, $maxTokens * 2);
                    MaxTokensProbe::remember($probeKey, $maxTokens);
                    if ($onRetry) $onRetry($attempt, 4, 'Response was cut off — retrying with more room…', 0.0);
                    continue;
                }
                throw new \RuntimeException('DeepSeek output was cut off (too long) even at the model\'s ceiling. Try a simpler description or a different model.');
            }

            $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
            $text = trim($text);
            $this->lastRawText = $text;

            $plan = json_decode($text, true);
            if (!is_array($plan)) {
                $plan = ai_lenient_json($text);
            }
            if (!is_array($plan)) {
                throw new \RuntimeException('DeepSeek response was not valid JSON: ' . substr($text, 0, 200));
            }

            return $plan;
        }

        throw $lastError;
    }
}

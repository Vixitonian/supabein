<?php

declare(strict_types=1);

namespace SupaBein;

// Zhipu / BigModel (open.bigmodel.cn), OpenAI-compatible chat-completions
// endpoint. All three GLM models here are "thinking" models -- every reply
// carries a separate `reasoning_content` field ahead of the real `content`,
// and glm-4.7-flash in particular burns a LOT of budget on it: live-tested
// against a trivial 2-field JSON request, it used ~900 tokens of pure
// reasoning before ever emitting content, and finish_reason came back
// "length" (content empty) at anything under a few thousand max_tokens.
// glm-4.5-flash is far cheaper (~100 tokens total for the same prompt) and
// is the default for exactly that reason. glm-5.2 is Zhipu's flagship
// model (live-verified directly against this same endpoint) -- also a
// reasoning model, opt-in via the model picker rather than the default.
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

    // job 218 (task #198): a single generateJson*() call can silently retry
    // up to 4 times inside call() below -- each its own full HTTP round trip,
    // some with a backoff sleep on top -- while the outer pipeline stage that
    // called it (e.g. "Designing database schema…") has already logged its
    // one "start" event and won't log anything else until this WHOLE call
    // finally returns or exhausts its retries. Live-caught: a schema stage
    // that took 6m43s against a normal 20-55s, entirely invisible to the
    // live progress UI even though the retries themselves were the system
    // correctly recovering, not something actually stuck. $onRetry is
    // optional and additive -- every existing caller that doesn't pass it
    // behaves exactly as before.
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

            // cached_tokens: Zhipu's context caching is automatic (no
            // cache_control param -- sending one is REJECTED, unlike
            // Anthropic/OpenAI) based on detecting an identical/near-identical
            // prefix across requests. Every agent loop already sends the same
            // $agentPrompt (system prompt) unchanged on every turn with only
            // the trailing user message growing, which is exactly the
            // cache-friendly shape their docs describe -- but nothing has
            // ever captured whether that's actually resulting in cache hits.
            // Purely additive visibility, not a behavior change: this is the
            // prerequisite for ever answering "is the repeated system prompt
            // costing what it looks like it should, or is caching already
            // absorbing most of it" with real numbers instead of a guess.
            $raw = $envelope['usage'] ?? [];
            $this->lastUsage = [
                'prompt_tokens'     => (int)($raw['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($raw['completion_tokens'] ?? 0),
                'total_tokens'      => (int)($raw['total_tokens'] ?? 0),
                'cached_tokens'     => (int)($raw['prompt_tokens_details']['cached_tokens'] ?? 0),
            ];

            // The reasoning trace ran the whole max_tokens budget out before
            // real content ever finished -- same failure shape as every other
            // client's "cut off" case, just far more likely to happen here
            // given how reasoning-heavy these models are. Triggers on
            // finish_reason alone (not just empty content) -- a NON-empty but
            // truncated reply (e.g. a write_file call cut off mid-content)
            // would otherwise fall straight into the JSON-parse path below
            // and fail as "not valid JSON", spending a whole agent-loop turn
            // on a failure this HTTP-level retry can usually just fix outright.
            // 200000 is a deliberately generous ceiling, not a per-model
            // limit -- if it's too high for this specific model, the
            // "max_tokens out of range" handler above already catches that
            // on the next attempt and corrects back down, so raising this
            // never risks a worse failure, only gives newer/larger-ceiling
            // models (glm-5.2 and beyond) room to actually use it.
            if ($finishReason === 'length') {
                if ($attempt < 4 && $maxTokens < 200000) {
                    $maxTokens = min(200000, $maxTokens * 2);
                    MaxTokensProbe::remember($probeKey, $maxTokens);
                    if ($onRetry) $onRetry($attempt, 4, 'Response was cut off — retrying with more room…', 0.0);
                    continue;
                }
                if ($text === null || trim($text) === '') {
                    throw new \RuntimeException('Zhipu output was cut off by its own reasoning before producing a reply. Try a simpler prompt or a different model.');
                }
                // Still truncated at the ceiling, but at least partial
                // content came back -- let the lenient-JSON salvage below
                // have a shot at it instead of failing outright.
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

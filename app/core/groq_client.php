<?php

declare(strict_types=1);

namespace SupaBein;

class GroqClient
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    // Deliberately generous starting point — the real per-model ceiling is
    // auto-discovered from the API's own rejection message on first use
    // (see MaxTokensProbe) rather than hand-maintained here.
    private const MAX_TOKENS_DEFAULT = 32000;

    // Groq's free tier enforces a per-model tokens-per-minute (TPM) budget
    // that covers the WHOLE request (prompt + max_tokens together), measured
    // live off the API's own x-ratelimit-limit-tokens response header since
    // Groq doesn't publish these numbers anywhere else. A flat 32000
    // max_tokens blows every one of these on its own regardless of prompt
    // size, so the real starting point has to leave room for the prompt —
    // see the budget calculation in call() below.
    private const MODEL_TPM_BUDGET = [
        'llama-3.3-70b-versatile' => 12000,
        'openai/gpt-oss-120b'     => 8000,
        'llama-3.1-8b-instant'    => 6000,
    ];

    private array $lastUsage = [];
    private string $lastRawText = '';

    public function __construct(
        private string $apiKey,
        private string $model = 'llama-3.3-70b-versatile'
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

    /**
     * @param array $attachments Accepted only for interface parity with the
     *   other provider clients -- Groq's chat-completions models here are
     *   text-only, so attachments are silently ignored rather than erroring.
     *   Deliberate degrade: this client is typically reached deep in the
     *   fallback chain, and dropping an image there beats failing outright.
     */
    public function generateJson(string $systemPrompt, string $userPrompt, array $attachments = []): array
    {
        return $this->call([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ]);
    }

    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt, array $attachments = []): array
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
        $probeKey  = 'groq:' . $this->model;

        $promptChars = 0;
        foreach ($messages as $m) $promptChars += strlen((string)($m['content'] ?? ''));
        $promptEstimate = (int)ceil($promptChars / 4);
        $tpmBudget = self::MODEL_TPM_BUDGET[$this->model] ?? 6000;
        $budgetDefault = max(512, $tpmBudget - $promptEstimate - 200);

        $maxTokens = MaxTokensProbe::initial($probeKey, min(self::MAX_TOKENS_DEFAULT, $budgetDefault));

        $lastError = null;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $body = [
                'model'           => $this->model,
                'messages'        => $messages,
                'max_tokens'      => $maxTokens,
                'stream'          => false,
                // Unlike OpenRouter (which fans out to many backing providers
                // with inconsistent support), Groq hosts these models itself
                // and reliably honors json_object mode -- every system prompt
                // in this codebase already describes a JSON shape and says
                // "json" explicitly, which is all Groq requires to accept this.
                'response_format' => ['type' => 'json_object'],
            ];
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
                CURLOPT_TIMEOUT        => 420,
                CURLOPT_CONNECTTIMEOUT => 10,
                // Captured so a 429 can honor the server's own Retry-After
                // instead of guessing at a backoff.
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
                throw new \RuntimeException('Groq network error: ' . $curlErr);
            }

            if ($httpCode !== 200) {
                $errBody = json_decode($response, true);
                $msg = $errBody['error']['message'] ?? ('HTTP ' . $httpCode . ': ' . substr($response, 0, 300));
                $msg = is_string($msg) ? $msg : json_encode($msg);
                $lastError = new \RuntimeException('Groq error: ' . $msg);

                // A too-high max_tokens is self-correcting: the error almost
                // always states the real ceiling for this model, so shrink to
                // it and retry -- same mechanism as the other clients.
                if ($attempt < 4 && stripos($msg, 'token') !== false) {
                    $corrected = MaxTokensProbe::extractLimit($msg, $maxTokens);
                    if ($corrected !== null) {
                        $maxTokens = $corrected;
                        MaxTokensProbe::remember($probeKey, $maxTokens);
                        continue;
                    }
                }
                // Retry on 5xx or 429 (rate limit) -- honor the server's own
                // Retry-After when it sends one (capped at 60s), same
                // reasoning as NvidiaClient: a tight retry loop hammering a
                // 429 with no delay burns the whole turn budget on rate-limit
                // errors instead of ever getting a real generation through.
                if (($httpCode >= 500 || $httpCode === 429) && $attempt < 4) {
                    if ($httpCode === 429) {
                        $retryAfter = isset($responseHeaders['retry-after']) ? (float)$responseHeaders['retry-after'] : null;
                        $wait = $retryAfter !== null ? min(60.0, max(1.0, $retryAfter)) : (10 * $attempt);
                    } else {
                        $wait = $attempt * 2;
                    }
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
                throw new \RuntimeException('Groq returned no content in response');
            }

            if ($finishReason === 'length') {
                throw new \RuntimeException('Groq output was cut off (too long). Try a simpler description or use Gemini for large builds.');
            }

            // Strip <think>...</think> blocks some reasoning-capable Groq
            // models (e.g. the gpt-oss family) can prepend to the content.
            $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
            $text = trim($text);
            $this->lastRawText = $text;

            $plan = json_decode($text, true);
            if (!is_array($plan)) {
                $plan = ai_lenient_json($text);
            }
            if (!is_array($plan)) {
                throw new \RuntimeException('Groq response was not valid JSON: ' . substr($text, 0, 200));
            }

            return $plan;
        }

        throw $lastError;
    }
}

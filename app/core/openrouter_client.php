<?php

declare(strict_types=1);

namespace SupaBein;

class OpenRouterClient
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    // Deliberately generous starting point. Different models routed through
    // OpenRouter (and different underlying providers pre-authorizing spend
    // against max_tokens) have wildly different real ceilings — rather than
    // hand-maintain a per-model override table that goes stale the moment a
    // new model is added, the real ceiling is auto-discovered from the
    // rejecting error's own message on first use (see MaxTokensProbe) and
    // cached from then on.
    private const MAX_TOKENS_DEFAULT = 100000;

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

    /**
     * @param array<int, array{media_type:string, data_base64:string}> $attachments
     *   Reference images to ground the response in. Only image/* is sent —
     *   OpenRouter fans out to many different underlying models and not all
     *   of them support document/PDF input via the OpenAI-compatible schema,
     *   so a PDF attachment is silently dropped here rather than risking a
     *   hard failure on what's meant to be a resilient fallback path.
     */
    public function generateJson(string $systemPrompt, string $userPrompt, array $attachments = []): array
    {
        return $this->call([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => self::userContent($userPrompt, $attachments)],
        ]);
    }

    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt, array $attachments = []): array
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
        $messages[] = ['role' => 'user', 'content' => self::userContent($userPrompt, $attachments)];
        return $this->call($messages);
    }

    /** @return string|array Plain text when there's nothing to attach, else OpenAI-style content parts. */
    private static function userContent(string $userPrompt, array $attachments)
    {
        $images = array_values(array_filter($attachments, fn($a) => isset($a['media_type'], $a['data_base64']) && str_starts_with($a['media_type'], 'image/')));
        if (!$images) return $userPrompt;

        $content = [['type' => 'text', 'text' => $userPrompt]];
        foreach ($images as $att) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $att['media_type'] . ';base64,' . $att['data_base64']]];
        }
        return $content;
    }

    private function call(array $messages): array
    {
        $probeKey  = 'openrouter:' . $this->model;
        $maxTokens = MaxTokensProbe::initial($probeKey, self::MAX_TOKENS_DEFAULT);

        // Several free-tier models route through a single backing provider with no
        // OpenRouter-side failover, so a transient upstream hiccup surfaces as an
        // outright request failure. Retry rate limits and 5xx before giving up;
        // a too-high max_tokens gets one correction attempt too (see below).
        $lastError = null;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $body = [
                'model'      => $this->model,
                'messages'   => $messages,
                // Not all routed providers support response_format=json_object (some reject
                // the request outright, others silently drop the final answer into a
                // "reasoning" field). We rely on the system prompt + ai_lenient_json() instead.
                'max_tokens' => $maxTokens,
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
                $msg = is_string($msg) ? $msg : json_encode($msg);
                $lastError = new \RuntimeException('OpenRouter error: ' . $msg);

                // A too-high max_tokens is self-correcting: the error (almost
                // always) states the real ceiling for this model/provider, so
                // shrink to it and retry — same mechanism as AnthropicClient,
                // and what replaced the old hand-maintained override table.
                // Not gated on a specific HTTP status: OpenRouter's own
                // credit-balance variant of this error ("can only afford N")
                // has been observed on codes other than a plain 400, and
                // extractLimit() itself is already the real safety gate —
                // it only ever returns a value strictly below what was sent.
                if ($attempt < 4 && stripos($msg, 'token') !== false) {
                    $corrected = MaxTokensProbe::extractLimit($msg, $maxTokens);
                    if ($corrected !== null) {
                        $maxTokens = $corrected;
                        MaxTokensProbe::remember($probeKey, $maxTokens);
                        continue;
                    }
                }
                if (($httpCode === 429 || $httpCode >= 500) && $attempt < 4) {
                    sleep($attempt * 2);
                    continue;
                }
                throw $lastError;
            }

            MaxTokensProbe::remember($probeKey, $maxTokens);

            $envelope     = json_decode($response, true);
            $text         = $envelope['choices'][0]['message']['content'] ?? null;
            $finishReason = $envelope['choices'][0]['finish_reason'] ?? null;

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
                // finish_reason "length" means the model got cut off mid-response —
                // report that plainly instead of a generic parse error, since the
                // fix (shorter request / different model) is completely different
                // from an actually-malformed response.
                if ($finishReason === 'length') {
                    throw new \RuntimeException(
                        "OpenRouter response was cut off before finishing (hit the {$maxTokens}-token limit for {$this->model}) — "
                        . 'try a simpler request, or switch to a different model.'
                    );
                }
                throw new \RuntimeException('OpenRouter response was not valid JSON: ' . substr($text, 0, 200));
            }

            return $plan;
        }

        throw $lastError;
    }
}

<?php

declare(strict_types=1);

namespace SupaBein;

class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private array $lastUsage = [];
    private string $lastRawText = '';

    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-2.5-flash',
        // 420s (7min) suits the app-builder's own long generation jobs, the
        // default every existing caller keeps getting. A short-lived,
        // interactive use (an AI Assistant chat turn via FallbackAiClient)
        // passes something far smaller instead, so a slow/unresponsive
        // model gets abandoned in seconds rather than minutes -- the whole
        // point of a multi-model fallback chain is defeated if the first
        // candidate is allowed to sit there for up to 7 minutes before the
        // chain ever gets a chance to try the next one.
        private int $timeoutSeconds = 420
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
     * Send a single-turn prompt and return parsed JSON.
     *
     * @throws \RuntimeException on network error, HTTP error, or non-JSON response
     */
    /**
     * @param array<int, array{media_type:string, data_base64:string}> $attachments
     *   Reference images/PDFs to ground the response in — e.g. a logo to match
     *   exactly, or a sample document to build a schema/UI from, rather than
     *   inventing plausible-looking placeholders. Gemini accepts both image/*
     *   and application/pdf as inlineData.
     */
    public function generateJson(string $systemPrompt, string $userPrompt, array $attachments = [], bool $jsonMode = true): array
    {
        return $this->generateJsonWithHistory($systemPrompt, [], $userPrompt, $attachments, $jsonMode);
    }

    /**
     * Send a multi-turn conversation and return parsed JSON.
     *
     * $history is an array of ['role' => 'user'|'model', 'text' => string].
     * $attachments (only ever attached to the final/current user turn, never
     * to history — see generateJson()'s doc comment) are inlineData parts:
     * ['media_type' => 'image/png'|'application/pdf'|..., 'data_base64' => string].
     *
     * @throws \RuntimeException on network error, HTTP error, or non-JSON response
     */
    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt, array $attachments = [], bool $jsonMode = true): array
    {
        $url  = sprintf(self::ENDPOINT, urlencode($this->model));
        $url .= '?key=' . urlencode($this->apiKey);

        $contents = [];
        foreach ($history as $turn) {
            if (!isset($turn['role'], $turn['text'])) continue;
            $contents[] = ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]];
        }
        $userParts = [['text' => $userPrompt]];
        foreach ($attachments as $att) {
            if (!isset($att['media_type'], $att['data_base64'])) continue;
            $userParts[] = ['inlineData' => ['mimeType' => $att['media_type'], 'data' => $att['data_base64']]];
        }
        $contents[] = ['role' => 'user', 'parts' => $userParts];

        $payload = json_encode([
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'          => $contents,
            // Conversational chat callers (Catalog::callAiAssistant()) pass
            // jsonMode: false -- forcing responseMimeType here would otherwise
            // make even a plain "Hi there!" reply come back wrapped as some
            // JSON value instead of natural text.
            'generationConfig'  => $jsonMode
                ? ['responseMimeType' => 'application/json', 'maxOutputTokens' => 65536]
                : ['maxOutputTokens' => 65536],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = null; $httpCode = 0;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException('Gemini API network error: ' . $curlErr);
            }

            if ($httpCode === 200) break;

            $body = json_decode($response, true);
            $msg  = $body['error']['message'] ?? ('HTTP ' . $httpCode);
            // 5xx/429 without a backoff here means a tight agentic loop (many
            // calls in quick succession) hits the rate limit, the caller's own
            // catch-and-retry fires with no delay, hits it again instantly,
            // and burns its whole turn budget on rate-limit errors in
            // milliseconds instead of ever getting a real generation through.
            if (($httpCode >= 500 || $httpCode === 429) && $attempt < 4) {
                sleep($attempt * 2);
                continue;
            }
            throw new \RuntimeException('Gemini API error: ' . $msg);
        }

        $envelope     = json_decode($response, true);
        $text         = $envelope['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $finishReason = $envelope['candidates'][0]['finishReason'] ?? null;

        $meta = $envelope['usageMetadata'] ?? [];
        $this->lastUsage = [
            'prompt_tokens'     => (int)($meta['promptTokenCount'] ?? 0),
            'completion_tokens' => (int)($meta['candidatesTokenCount'] ?? 0),
            'total_tokens'      => (int)($meta['totalTokenCount'] ?? 0),
        ];

        if ($text === null) {
            // "MAX_TOKENS" with no text at all means the reasoning trace (this
            // model 'thinks' before answering) burned the entire budget --
            // maxOutputTokens above is already fixed at Gemini 2.5 Flash's own
            // ceiling (unlike the other providers, there's no higher value to
            // retry with), so the only honest fix is a clearer error telling
            // the caller WHY, instead of the generic "no content" message.
            if ($finishReason === 'MAX_TOKENS') {
                throw new \RuntimeException('Gemini output was cut off by its own reasoning before producing a reply, even at its max output size. Try a simpler prompt or a different model.');
            }
            throw new \RuntimeException('Gemini returned no content in response');
        }
        $this->lastRawText = $text;

        $plan = json_decode($text, true);
        if (!is_array($plan)) {
            $plan = ai_lenient_json($text);
        }
        if (!is_array($plan)) {
            if ($finishReason === 'MAX_TOKENS') {
                throw new \RuntimeException('Gemini response was cut off before finishing (hit its max output size) -- try a simpler request, or switch to a different model.');
            }
            throw new \RuntimeException('Gemini response was not valid JSON: ' . substr($text, 0, 200));
        }

        return $plan;
    }
}

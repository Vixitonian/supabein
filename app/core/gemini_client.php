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
        private string $model = 'gemini-2.5-flash'
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
    public function generateJson(string $systemPrompt, string $userPrompt): array
    {
        return $this->generateJsonWithHistory($systemPrompt, [], $userPrompt);
    }

    /**
     * Send a multi-turn conversation and return parsed JSON.
     *
     * $history is an array of ['role' => 'user'|'model', 'text' => string].
     *
     * @throws \RuntimeException on network error, HTTP error, or non-JSON response
     */
    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt): array
    {
        $url  = sprintf(self::ENDPOINT, urlencode($this->model));
        $url .= '?key=' . urlencode($this->apiKey);

        $contents = [];
        foreach ($history as $turn) {
            if (!isset($turn['role'], $turn['text'])) continue;
            $contents[] = ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userPrompt]]];

        $payload = json_encode([
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'          => $contents,
            'generationConfig'  => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 65536],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = null; $httpCode = 0;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 420,
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

        $envelope = json_decode($response, true);
        $text     = $envelope['candidates'][0]['content']['parts'][0]['text'] ?? null;

        $meta = $envelope['usageMetadata'] ?? [];
        $this->lastUsage = [
            'prompt_tokens'     => (int)($meta['promptTokenCount'] ?? 0),
            'completion_tokens' => (int)($meta['candidatesTokenCount'] ?? 0),
            'total_tokens'      => (int)($meta['totalTokenCount'] ?? 0),
        ];

        if ($text === null) {
            throw new \RuntimeException('Gemini returned no content in response');
        }
        $this->lastRawText = $text;

        $plan = json_decode($text, true);
        if (!is_array($plan)) {
            $plan = ai_lenient_json($text);
        }
        if (!is_array($plan)) {
            throw new \RuntimeException('Gemini response was not valid JSON: ' . substr($text, 0, 200));
        }

        return $plan;
    }
}

<?php

declare(strict_types=1);

namespace SupaBein;

class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    // Deliberately generous starting point — the real per-model ceiling is
    // auto-discovered from the API's own error message on the first call to
    // any given model (see MaxTokensProbe) rather than hand-maintained here,
    // so a newer/higher-limit model automatically gets to use more without
    // a code change, and an older/lower-limit one self-corrects instead of
    // just failing.
    private const MAX_TOKENS_DEFAULT = 128000;

    private array $lastUsage = [];
    private string $lastRawText = '';

    public function __construct(
        private string $apiKey,
        private string $model = 'claude-opus-4-8'
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
     * Anthropic uses 'assistant' rather than 'model' for the model's turns.
     *
     * @throws \RuntimeException on network error, HTTP error, or non-JSON response
     */
    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt): array
    {
        $messages = [];
        foreach ($history as $turn) {
            if (!isset($turn['role'], $turn['text'])) continue;
            $role = $turn['role'] === 'model' ? 'assistant' : $turn['role'];
            $messages[] = ['role' => $role, 'content' => $turn['text']];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $probeKey  = 'anthropic:' . $this->model;
        $maxTokens = MaxTokensProbe::initial($probeKey, self::MAX_TOKENS_DEFAULT);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $payload = json_encode([
                'model'      => $this->model,
                'max_tokens' => $maxTokens,
                'system'     => $systemPrompt,
                'messages'   => $messages,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                ],
                CURLOPT_TIMEOUT        => 420,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException('Anthropic API network error: ' . $curlErr);
            }

            if ($httpCode !== 200) {
                $body = json_decode($response, true);
                $msg  = $body['error']['message'] ?? ('HTTP ' . $httpCode);
                $msg  = is_string($msg) ? $msg : json_encode($msg);

                // A too-high max_tokens is self-correcting: the error states
                // the real ceiling for this model, so shrink to it and retry
                // once instead of failing outright — this is what lets every
                // model "use the max it can" without a hand-tuned table.
                if ($attempt < 3 && stripos($msg, 'max_tokens') !== false) {
                    $corrected = MaxTokensProbe::extractLimit($msg, $maxTokens);
                    if ($corrected !== null) {
                        $maxTokens = $corrected;
                        MaxTokensProbe::remember($probeKey, $maxTokens);
                        continue;
                    }
                }
                throw new \RuntimeException('Anthropic API error: ' . $msg);
            }

            // Worked — remember it so later calls to this model in this
            // process skip straight to a known-good value.
            MaxTokensProbe::remember($probeKey, $maxTokens);

            $envelope = json_decode($response, true);
            $text = null;
            foreach ($envelope['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text = $block['text'];
                    break;
                }
            }

            $usage = $envelope['usage'] ?? [];
            $inputTokens  = (int)($usage['input_tokens'] ?? 0);
            $outputTokens = (int)($usage['output_tokens'] ?? 0);
            $this->lastUsage = [
                'prompt_tokens'     => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens'      => $inputTokens + $outputTokens,
            ];

            if ($text === null) {
                throw new \RuntimeException('Anthropic returned no text content in response');
            }
            $this->lastRawText = $text;

            $plan = json_decode($text, true);
            if (!is_array($plan)) {
                $plan = ai_lenient_json($text);
            }
            if (!is_array($plan)) {
                throw new \RuntimeException('Anthropic response was not valid JSON: ' . substr($text, 0, 200));
            }

            return $plan;
        }

        throw new \RuntimeException('Anthropic API error: could not determine a working max_tokens value for ' . $this->model);
    }
}

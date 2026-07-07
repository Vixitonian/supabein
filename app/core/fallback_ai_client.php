<?php

declare(strict_types=1);

namespace SupaBein;

/**
 * Wraps an ordered list of (provider, model) candidates behind the same
 * interface every raw provider client exposes (generateJson,
 * generateJsonWithHistory, getLastUsage, getLastRawText), so every existing
 * caller of make_ai_client() gets automatic fallback for free with no changes
 * of their own. On a hard, unrecoverable provider error (rate limit exhausted,
 * out of credits, invalid key — see ai_is_unrecoverable_provider_error()) on
 * the currently active candidate, transparently rebuilds the underlying
 * client against the next candidate in the list and retries the SAME call —
 * the caller never sees the failure unless every candidate is exhausted.
 *
 * A transient/recoverable error (a malformed JSON response, a network blip)
 * is NOT treated as a reason to fall back — that's the caller's own
 * turn-loop's job to retry, exactly as before this class existed. Falling
 * back only ever happens for the class of error that would otherwise fail
 * the entire job outright no matter how many more turns it had left.
 */
class FallbackAiClient
{
    private array $candidates;
    private int $index = 0;
    private array $config;
    private object $client;
    private array $lastUsage = [];
    /** @var array<int, array{from_provider:string, from_model:string, to_provider:string, to_model:string, error:string}> */
    private array $fallbackEvents = [];

    public function __construct(array $config, array $candidates)
    {
        if (!$candidates) {
            throw new \InvalidArgumentException('FallbackAiClient needs at least one candidate');
        }
        $this->config     = $config;
        $this->candidates = array_values($candidates);
        $this->client      = $this->buildClient($this->candidates[0]);
    }

    private function buildClient(array $candidate): object
    {
        // ai_make_single_client() builds exactly one raw client — never
        // make_ai_client() itself, which now returns a FallbackAiClient and
        // would recurse infinitely. Resolved via PHP's normal namespace
        // fallback to the global function of the same name.
        return \ai_make_single_client($this->config, $candidate['provider'], $candidate['model']);
    }

    public function getActiveProvider(): string
    {
        return $this->candidates[$this->index]['provider'];
    }

    public function getActiveModel(): string
    {
        return $this->candidates[$this->index]['model'];
    }

    /** Non-empty only if at least one fallback actually happened this run. */
    public function getFallbackEvents(): array
    {
        return $this->fallbackEvents;
    }

    public function getLastUsage(): array
    {
        return $this->lastUsage;
    }

    public function getLastRawText(): string
    {
        return method_exists($this->client, 'getLastRawText') ? $this->client->getLastRawText() : '';
    }

    public function generateJson(string $systemPrompt, string $userPrompt, array $attachments = []): array
    {
        return $this->call(fn(object $c): array => $c->generateJson($systemPrompt, $userPrompt, $attachments));
    }

    public function generateJsonWithHistory(string $systemPrompt, array $history, string $userPrompt, array $attachments = []): array
    {
        return $this->call(fn(object $c): array => $c->generateJsonWithHistory($systemPrompt, $history, $userPrompt, $attachments));
    }

    private function call(\Closure $invoke): array
    {
        while (true) {
            try {
                $result = $invoke($this->client);
                $this->lastUsage = $this->client->getLastUsage();
                return $result;
            } catch (\Throwable $e) {
                if (!\ai_is_unrecoverable_provider_error($e->getMessage())) {
                    throw $e;
                }
                $from = $this->candidates[$this->index];
                if (!isset($this->candidates[$this->index + 1])) {
                    // Every candidate exhausted — nothing left to fall back to.
                    throw $e;
                }
                $this->index++;
                $to = $this->candidates[$this->index];
                $this->fallbackEvents[] = [
                    'from_provider' => $from['provider'], 'from_model' => $from['model'],
                    'to_provider'   => $to['provider'],   'to_model'   => $to['model'],
                    'error'         => $e->getMessage(),
                ];
                $this->client = $this->buildClient($to);
            }
        }
    }
}

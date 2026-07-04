<?php

declare(strict_types=1);

namespace SupaBein;

// Auto-discovers each model's real max_tokens ceiling instead of hand-
// maintaining a per-model table (which goes stale — under-shooting new
// models that support more, or drifting wrong when a provider changes a
// limit). Each client starts a request with a deliberately generous
// max_tokens; if the provider rejects it as too high, the real ceiling is
// almost always stated in the error message itself, so we parse it out and
// retry once with the corrected value. Discovered values are cached for the
// lifetime of the PHP process — a job that calls the same model many times
// in a row (e.g. the agentic edit loop) only pays the one-time correction
// once, not on every turn.
class MaxTokensProbe
{
    private static array $discovered = [];

    public static function initial(string $key, int $default): int
    {
        return self::$discovered[$key] ?? $default;
    }

    public static function remember(string $key, int $limit): void
    {
        self::$discovered[$key] = $limit;
    }

    // Recognizes a handful of "max_tokens too high" phrasings seen across
    // Anthropic and OpenAI-compatible (OpenRouter/NVIDIA) providers. Only
    // ever returns a value strictly lower than what was requested — never
    // trusts an unrelated number in some other error message enough to
    // shrink a model that isn't actually limited.
    public static function extractLimit(string $errorMessage, int $requested): ?int
    {
        if (preg_match('/max_tokens:\s*\d+\s*>\s*(\d+)/i', $errorMessage, $m)) {
            $limit = (int)$m[1];
        } elseif (preg_match('/(?:max(?:imum)?|at most|supports up to)[^\d]{0,40}?(\d{3,7})\s*(?:completion|output)\s*tokens?/i', $errorMessage, $m)) {
            // The qualifier ("completion"/"output") is required, not optional —
            // a bare "maximum context length is N tokens" is a totally different
            // limit (the context window, not the output cap) and must NOT match
            // here, or a large-context request would get its max_tokens wrongly
            // clamped down to the context-length number instead of the real
            // (and likely much larger) output ceiling.
            $limit = (int)$m[1];
        } else {
            return null;
        }
        if ($limit <= 0 || $limit >= $requested) return null;
        return $limit;
    }
}

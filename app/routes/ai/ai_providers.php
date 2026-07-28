<?php

declare(strict_types=1);



// ─── AI provider factory ─────────────────────────────────────────────────────

const AI_ALLOWED_PROVIDERS = ['gemini', 'groq', 'openrouter', 'nvidia', 'anthropic', 'zhipu', 'deepseek'];
const AI_ALLOWED_MODELS = [
    'gemini' => [
        'gemini-2.5-flash',
    ],
    // Free tier, hosted directly by Groq (not fanned out to third-party
    // backing providers the way openrouter's are) -- ordered best-to-least
    // capable, same convention as every other provider below.
    'groq' => [
        'llama-3.3-70b-versatile',
        'openai/gpt-oss-120b',
        'llama-3.1-8b-instant',
    ],
    'anthropic' => [
        'claude-opus-4-8',
        'claude-sonnet-5',
    ],
    // Zhipu / BigModel (GLM). glm-4.5-flash is the efficient default (small
    // reasoning overhead); glm-4.7-flash is a much heavier "thinking" model
    // that needs a large max_tokens budget to get past its own reasoning
    // trace before it ever emits real content -- see ZhipuClient's own
    // token-budget comment for the live-measured numbers. glm-5.2 is Zhipu's
    // flagship model, live-verified directly against open.bigmodel.cn (HTTP
    // 200, correct completion) -- also a reasoning model (carries its own
    // reasoning_content), same MAX_TOKENS_DEFAULT budget/retry logic applies.
    // Kept out of index 0 deliberately -- glm-4.5-flash stays the default for
    // an unrecognized model string; glm-5.2 is opt-in via the model picker.
    'zhipu' => [
        'glm-4.5-flash',
        'glm-4.7-flash',
        'glm-5.2',
    ],
    // DeepSeek's own API (not NVIDIA's hosted copy, which already appears
    // under 'nvidia' above as deepseek-ai/deepseek-v4-*) -- this account's
    // DeepSeek balance is $0 at wiring time (confirmed via a live 402
    // "Insufficient Balance" response, distinct from the 401 a bad key
    // returns), so every real call fails on billing until funded. Wired in
    // anyway per explicit request -- starts working the moment it's funded,
    // no code change needed.
    'deepseek' => [
        'deepseek-v4-flash',
    ],
    // Ordered best-to-least capable within each provider (index 0 is also that
    // provider's fallback default when an unrecognized model is requested).
    //
    // This account's OpenRouter balance is $0 (no credits ever purchased) --
    // live-verified that every plain (non ":free") model here fails outright
    // with "Insufficient credits" regardless of which one is tried, while
    // the ":free"-suffixed models work with no balance at all. Since
    // ai_build_fallback_chain() interleaves tiers across providers (this
    // provider's index-0 model is tried before nvidia's, index-1 before
    // nvidia's tier 1, etc.), having a paid model sitting at index 0 meant
    // this whole provider was effectively skipped every time -- gemini/groq
    // failing would fall straight to nvidia's nemotron (observed producing
    // garbled, repeated-key JSON) without ever trying any of the working
    // free models below. Reordered so a confirmed-working free model leads:
    // 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free' was live-verified
    // end-to-end against the real flyer-planner prompt (~7.4k tokens),
    // returning a complete, correctly-shaped plan. The paid models are kept
    // at the end rather than removed -- harmless (fast, cleanly-classified
    // "insufficient credits" failures, see
    // ai_is_unrecoverable_provider_error) and immediately useful the moment
    // this account is ever funded, with no code change needed.
    //
    // 'nvidia/nemotron-3-super-120b-a12b:free' is deliberately EXCLUDED
    // (not just deprioritized): live-tested and it returned garbled,
    // non-JSON prose instead of a parseable object. That failure mode isn't
    // an availability problem (see ai_is_unrecoverable_provider_error) --
    // it's this specific model failing to follow the format instruction --
    // so it wouldn't reliably fall forward, it would just burn the caller's
    // retry budget hitting the same broken candidate again.
    'openrouter' => [
        'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
        'openai/gpt-oss-20b:free',
        'cohere/north-mini-code:free',
        'google/gemma-4-26b-a4b-it:free',
        'poolside/laguna-m.1:free',
        'poolside/laguna-xs.2:free',
        'openai/gpt-oss-120b:free',
        'nvidia/nemotron-3-nano-30b-a3b:free',
        'nvidia/nemotron-nano-9b-v2:free',
        'moonshotai/kimi-k2',
        'mistralai/mistral-small-3.2-24b-instruct',
        'nex-agi/nex-n2-pro',
    ],
    'nvidia' => [
        'nvidia/nemotron-3-ultra-550b-a55b',
        'z-ai/glm-5.2',
        'deepseek-ai/deepseek-v4-pro',
        'qwen/qwen3.5-122b-a10b',
        'deepseek-ai/deepseek-v4-flash',
    ],
];

// Single source of truth for the dashboard's model picker -- display order,
// label, and badge for every (provider, model) pair a user can actually
// choose. Previously duplicated as a hand-maintained array in
// dashboard/assets/app.js; that copy silently drifted from this one (a
// model added here never showed up there until someone remembered to patch
// both). The dashboard now fetches this via GET /v1/ai/models instead of
// hardcoding its own list -- see that route below, which also filters out
// any entry whose provider has no configured API key.
//
// Deliberately NOT auto-derived from AI_ALLOWED_MODELS's per-provider tier
// order: this list's cross-provider interleaving ("best overall" ordering,
// not grouped by provider) is a curated UX decision, not a mechanical one.
// The /v1/ai/models route below cross-checks every entry against
// AI_ALLOWED_MODELS so a typo'd or removed model here fails loud (filtered
// out) instead of silently offering a choice ai_make_single_client() would
// then quietly substitute away from.
const AI_MODEL_CATALOG = [
    ['label' => 'Claude Opus 4.8',       'provider' => 'anthropic',  'model' => 'claude-opus-4-8',                                    'badge' => 'Claude'],
    ['label' => 'Claude Sonnet 5',       'provider' => 'anthropic',  'model' => 'claude-sonnet-5',                                    'badge' => 'Claude'],
    ['label' => 'Nemotron 3 Ultra 550B', 'provider' => 'nvidia',     'model' => 'nvidia/nemotron-3-ultra-550b-a55b',                  'badge' => 'NVIDIA'],
    ['label' => 'Kimi K2',               'provider' => 'openrouter', 'model' => 'moonshotai/kimi-k2',                                 'badge' => 'OpenRouter'],
    ['label' => 'GLM 5.2',               'provider' => 'nvidia',     'model' => 'z-ai/glm-5.2',                                       'badge' => 'NVIDIA'],
    ['label' => 'GLM 5.2 (direct)',      'provider' => 'zhipu',      'model' => 'glm-5.2',                                            'badge' => 'Zhipu'],
    ['label' => 'GLM 4.5 Flash',         'provider' => 'zhipu',      'model' => 'glm-4.5-flash',                                      'badge' => 'Zhipu'],
    ['label' => 'GLM 4.7 Flash',         'provider' => 'zhipu',      'model' => 'glm-4.7-flash',                                      'badge' => 'Zhipu'],
    ['label' => 'DeepSeek V4 Flash (direct)', 'provider' => 'deepseek', 'model' => 'deepseek-v4-flash',                             'badge' => 'DeepSeek'],
    ['label' => 'DeepSeek V4 Pro',       'provider' => 'nvidia',     'model' => 'deepseek-ai/deepseek-v4-pro',                        'badge' => 'NVIDIA'],
    ['label' => 'Qwen 3.5 122B',         'provider' => 'nvidia',     'model' => 'qwen/qwen3.5-122b-a10b',                             'badge' => 'NVIDIA'],
    ['label' => 'Nemotron Super 120B',   'provider' => 'openrouter', 'model' => 'nvidia/nemotron-3-super-120b-a12b:free',             'badge' => 'Free'],
    ['label' => 'GPT OSS 120B',          'provider' => 'openrouter', 'model' => 'openai/gpt-oss-120b:free',                           'badge' => 'Free'],
    ['label' => 'DeepSeek V4 Flash',     'provider' => 'nvidia',     'model' => 'deepseek-ai/deepseek-v4-flash',                      'badge' => 'NVIDIA'],
    ['label' => 'Gemini 2.5 Flash',      'provider' => 'gemini',     'model' => 'gemini-2.5-flash',                                   'badge' => 'Fast'],
    ['label' => 'Laguna M.1',            'provider' => 'openrouter', 'model' => 'poolside/laguna-m.1:free',                           'badge' => 'Free'],
    ['label' => 'North Mini Code',       'provider' => 'openrouter', 'model' => 'cohere/north-mini-code:free',                        'badge' => 'Free'],
    ['label' => 'Mistral Small 3.2',     'provider' => 'openrouter', 'model' => 'mistralai/mistral-small-3.2-24b-instruct',           'badge' => 'OpenRouter'],
    ['label' => 'Nex N2 Pro',            'provider' => 'openrouter', 'model' => 'nex-agi/nex-n2-pro',                                 'badge' => 'OpenRouter'],
    ['label' => 'Gemma 4 26B (MoE)',     'provider' => 'openrouter', 'model' => 'google/gemma-4-26b-a4b-it:free',                     'badge' => 'Free'],
    ['label' => 'Nemotron Nano Omni',    'provider' => 'openrouter', 'model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free', 'badge' => 'Free'],
    ['label' => 'GPT OSS 20B',           'provider' => 'openrouter', 'model' => 'openai/gpt-oss-20b:free',                            'badge' => 'Free'],
    ['label' => 'Laguna XS.2',           'provider' => 'openrouter', 'model' => 'poolside/laguna-xs.2:free',                          'badge' => 'Free'],
];
// Builds exactly one raw provider client for one specific (provider, model).
// Only ever called (a) directly, for the simple single-provider case, or
// (b) from FallbackAiClient against candidates ai_build_fallback_chain()
// already filtered to providers with a configured key — never speculatively
// against an unconfigured one, since abort() below is a hard, uncatchable
// process exit (: never), not a throwable a try/catch could react to.
function ai_make_single_client(array $config, ?string $provider, ?string $model, ?int $timeoutSeconds = null): object
{
    $provider = in_array($provider, AI_ALLOWED_PROVIDERS, true)
        ? $provider
        : ($config['AI_PROVIDER'] ?? 'gemini');

    if ($provider === 'openrouter') {
        $key = $config['OPENROUTER_API_KEY'] ?? '';
        if (!$key) abort(503, 'OpenRouter API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['openrouter'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\OpenRouterClient($key, $model, $timeoutSeconds ?? 420);
    }

    if ($provider === 'nvidia') {
        $key = $config['NVIDIA_API_KEY'] ?? '';
        if (!$key) abort(503, 'NVIDIA API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['nvidia'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\NvidiaClient($key, $model, $timeoutSeconds ?? 420);
    }

    if ($provider === 'anthropic') {
        $key = $config['ANTHROPIC_API_KEY'] ?? '';
        if (!$key) abort(503, 'Anthropic API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['anthropic'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\AnthropicClient($key, $model, $timeoutSeconds ?? 420);
    }

    if ($provider === 'groq') {
        $key = $config['GROQ_API_KEY'] ?? '';
        if (!$key) abort(503, 'Groq API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['groq'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\GroqClient($key, $model, $timeoutSeconds ?? 420);
    }

    if ($provider === 'zhipu') {
        $key = $config['ZHIPU_API_KEY'] ?? '';
        if (!$key) abort(503, 'Zhipu API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['zhipu'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\ZhipuClient($key, $model, $timeoutSeconds ?? 420);
    }

    if ($provider === 'deepseek') {
        $key = $config['DEEPSEEK_API_KEY'] ?? '';
        if (!$key) abort(503, 'DeepSeek API key not configured on this server');
        $allowed = AI_ALLOWED_MODELS['deepseek'];
        $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
        return new \SupaBein\DeepSeekClient($key, $model, $timeoutSeconds ?? 420);
    }

    // Default: Gemini
    $key = $config['GEMINI_API_KEY'] ?? '';
    if (!$key) abort(503, 'AI build is not configured on this server (missing GEMINI_API_KEY)');
    $allowed = AI_ALLOWED_MODELS['gemini'];
    $model   = in_array($model, $allowed, true) ? $model : $allowed[0];
    return new \SupaBein\GeminiClient($key, $model, $timeoutSeconds ?? 420);
}

function ai_provider_configured(array $config, string $provider): bool
{
    return match ($provider) {
        'openrouter' => !empty($config['OPENROUTER_API_KEY']),
        'nvidia'     => !empty($config['NVIDIA_API_KEY']),
        'anthropic'  => !empty($config['ANTHROPIC_API_KEY']),
        'gemini'     => !empty($config['GEMINI_API_KEY']),
        'groq'       => !empty($config['GROQ_API_KEY']),
        'zhipu'      => !empty($config['ZHIPU_API_KEY']),
        'deepseek'   => !empty($config['DEEPSEEK_API_KEY']),
        default      => false,
    };
}
// ─── Image generation (AI Assistants "image" kind) ─────────────────────────
// Separate registry from the text/chat one above -- a provider or model
// valid for a text chat assistant has nothing to do with whether it's an
// image provider, and vice versa. Only Zhipu/CogView-4 today; structured the
// same way as AI_ALLOWED_PROVIDERS/AI_ALLOWED_MODELS so a second image
// provider slots in without touching Catalog::callAiAssistantImage() at all.
const AI_IMAGE_ALLOWED_PROVIDERS = ['zhipu'];
// cogview-3-flash listed first -- it's the priority/default choice
// (AI_IMAGE_ALLOWED_MODELS[$provider][0], same convention as the text
// registry above). Both models live-tested (2026-07) via a direct API call;
// both carry the identical watermark ZhipuImageClient already crops.
const AI_IMAGE_ALLOWED_MODELS = [
    'zhipu' => ['cogview-3-flash', 'cogview-4'],
];

function ai_image_provider_configured(array $config, string $provider): bool
{
    return match ($provider) {
        'zhipu' => !empty($config['ZHIPU_API_KEY']),
        default => false,
    };
}

// Returns raw image bytes (PNG). Mirrors ai_make_single_client()'s dispatch
// shape but for image generation, which has no shared client interface
// worth building yet (only one provider) -- add a real dispatch table here
// if/when a second one shows up.
function ai_generate_image(array $config, string $provider, string $model, string $prompt): string
{
    if ($provider === 'zhipu' && in_array($model, AI_IMAGE_ALLOWED_MODELS['zhipu'], true)) {
        $key = $config['ZHIPU_API_KEY'] ?? '';
        if (!$key) abort(503, 'Zhipu API key not configured on this server');
        return \SupaBein\ZhipuImageClient::generate($key, $prompt, $model);
    }
    throw new \RuntimeException("Unsupported image provider/model: $provider/$model");
}

// Whether a (provider, model) candidate is genuinely free to call, for
// ordering the no-preference fallback chain (see ai_build_fallback_chain())
// free-before-paid. Deliberately conservative: only providers/models with an
// explicit, documented free tier count -- Groq's entire catalog (hosted
// directly, not fanned out to paid backing providers -- see
// AI_ALLOWED_MODELS's own comment) and OpenRouter's ":free"-suffixed slugs.
// Gemini, Anthropic, NVIDIA, and OpenRouter's non-":free" models are all
// billed per-token (or, for this account specifically, fail outright on
// "insufficient credits" -- see AI_ALLOWED_MODELS's OpenRouter comment) and
// are treated as paid here even though the caller's own key might happen to
// have free trial credit remaining.
function ai_model_is_free(string $provider, string $model): bool
{
    if ($provider === 'groq') return true;
    if ($provider === 'openrouter') return str_ends_with($model, ':free');
    return false;
}

// Builds the ordered list of (provider, model) candidates a FallbackAiClient
// will try in turn. When the caller has an explicit preference — every real
// dashboard request does, via the model selector's getSelectedModel() —
// that candidate is the ONLY one returned: no silent cross-provider
// fallback. A user who picked a specific model expects that model to
// either do the job or visibly fail so they can switch and retry
// themselves (the dashboard's existing failed-job error + Retry button
// already re-reads the current selector on retry — this is the one piece
// that was missing). Falling back through every other provider/model
// combination behind their back means a "Gemini out of quota" or "NVIDIA
// insufficient credits" failure is invisible: the job just quietly
// finishes on a completely different model than the one they chose.
// Only when NO preference is given at all (preferredProvider is null) does
// this fall through to the old best-effort tier-by-tier default, for any
// caller that genuinely has no user-facing selection to honor. Never
// includes a provider with no configured key (see ai_make_single_client()'s
// doc comment for why that matters here).
function ai_build_fallback_chain(array $config, ?string $preferredProvider, ?string $preferredModel): array
{
    $chain = [];
    $seen  = [];
    $add = function (string $provider, string $model) use (&$chain, &$seen, $config): void {
        if (!ai_provider_configured($config, $provider)) return;
        $key = $provider . ':' . $model;
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $chain[] = ['provider' => $provider, 'model' => $model];
    };

    if ($preferredProvider !== null && in_array($preferredProvider, AI_ALLOWED_PROVIDERS, true)) {
        $models = AI_ALLOWED_MODELS[$preferredProvider] ?? [];
        $model  = ($preferredModel !== null && in_array($preferredModel, $models, true)) ? $preferredModel : ($models[0] ?? null);
        if ($model !== null) $add($preferredProvider, $model);
        return $chain;
    }

    // 1. Live-verified head-to-head against the app-builder's own agentic
    //    tool-calling loop (not just a single-shot prompt): glm-4.5-flash
    //    generated a working frontend in ~90s with zero wasted exploration,
    //    where nvidia's nano reasoning model below took 12-15 minutes on the
    //    same request, repeatedly hallucinating unrequested API endpoints and
    //    live-probing its own preview instead of trusting given context.
    //    Still just the FIRST candidate, not the only one -- everything below
    //    remains as a real fallback chain, unlike an explicit caller
    //    preference (which intentionally has no fallback at all, see above).
    $add('zhipu', 'glm-4.5-flash');
    // Previously first -- demoted, not removed, since every other free
    // candidate below (including this one) still recovers from an outage or
    // rate limit on the new first choice above.
    $add('openrouter', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free');

    // 2. Every other genuinely free model (see ai_model_is_free()), before
    //    any paid one -- $add() already no-ops the duplicate from step 1.
    foreach (AI_ALLOWED_PROVIDERS as $provider) {
        foreach (AI_ALLOWED_MODELS[$provider] ?? [] as $model) {
            if (ai_model_is_free($provider, $model)) $add($provider, $model);
        }
    }

    // 3. Only once every free candidate is queued, the paid/cost-unconfirmed
    //    ones -- same best-to-least-capable tier-interleave as before.
    $maxTier = max(array_map('count', AI_ALLOWED_MODELS));
    for ($tier = 0; $tier < $maxTier; $tier++) {
        foreach (AI_ALLOWED_PROVIDERS as $provider) {
            $models = AI_ALLOWED_MODELS[$provider] ?? [];
            if (isset($models[$tier]) && !ai_model_is_free($provider, $models[$tier])) $add($provider, $models[$tier]);
        }
    }

    return $chain;
}

// New public entry point — every existing caller of make_ai_client() gets
// automatic cross-provider/cross-model fallback for free, with no changes of
// their own, since FallbackAiClient exposes the exact same generateJson /
// generateJsonWithHistory / getLastUsage surface every raw client already did.
function make_ai_client(array $config, ?string $provider, ?string $model, ?int $timeoutSeconds = null): object
{
    $chain = ai_build_fallback_chain($config, $provider, $model);
    if (!$chain) {
        abort(503, 'No AI provider is configured on this server');
    }
    return new \SupaBein\FallbackAiClient($config, $chain, $timeoutSeconds);
}
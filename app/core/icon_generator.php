<?php

declare(strict_types=1);

namespace SupaBein;

// Generates a single icon-style PNG asset on demand: fetches an image from
// Pollinations.ai (free, keyless text-to-image) then cuts its background out
// via real ML segmentation -- tries the self-hosted rembg service first
// (config['REMBG_SERVICE_URL'] / config['REMBG_SHARED_SECRET'], a small
// Flask+rembg container on Render, see rembg-service/ at the repo root),
// falling back to remove.bg's API (config['REMOVEBG_API_KEY']) if rembg
// isn't configured or the request fails for any reason. At least one of the
// two must be configured.
class IconGenerator
{
    // Real human/person imagery is out of scope for this generator (same
    // policy as FlyGen's own curated picture bank) -- checked against the
    // caller-supplied subject before any external call is made.
    private const BLOCKED_SUBJECT_WORDS = [
        'person', 'people', 'human', 'humans', 'man', 'woman', 'men', 'women',
        'boy', 'girl', 'boys', 'girls', 'child', 'children', 'kid', 'kids',
        'baby', 'babies', 'toddler', 'teenager', 'adult', 'elderly', 'face',
        'faces', 'portrait', 'selfie', 'model', 'guy', 'lady', 'gentleman',
        'family', 'crowd', 'couple', 'bride', 'groom', 'worker', 'employee',
    ];

    private const IMAGE_SIZE = 512;

    public static function generate(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new \InvalidArgumentException('subject is required');
        }
        if (mb_strlen($subject) > 120) {
            throw new \InvalidArgumentException('subject is too long (max 120 characters)');
        }
        self::assertSubjectAllowed($subject);
        $config = self::assertBackgroundRemovalConfigured();

        $prompt = self::buildPrompt($subject);
        $raw = self::fetchFromPollinations($prompt);
        return self::removeBackground($raw, $config);
    }

    private static function assertBackgroundRemovalConfigured(): array
    {
        $config = \App::get('config');
        $hasRembg = !empty($config['REMBG_SERVICE_URL'] ?? '');
        $hasRemoveBg = !empty($config['REMOVEBG_API_KEY'] ?? '');
        if (!$hasRembg && !$hasRemoveBg) {
            throw new \RuntimeException(
                'Background removal is not configured yet (neither REMBG_SERVICE_URL nor REMOVEBG_API_KEY is set).'
            );
        }
        return $config;
    }

    private static function assertSubjectAllowed(string $subject): void
    {
        $normalized = ' ' . strtolower($subject) . ' ';
        foreach (self::BLOCKED_SUBJECT_WORDS as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $normalized)) {
                throw new \InvalidArgumentException(
                    'This generator only produces object/icon-style assets, not people or human figures.'
                );
            }
        }
    }

    private static function buildPrompt(string $subject): string
    {
        return sprintf(
            '3D clay render icon of %s, isolated, single object, centered, plain background, '
            . 'no people, no humans, no faces, no text, no watermark, product icon style',
            $subject
        );
    }

    private static function fetchFromPollinations(string $prompt): string
    {
        $url = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
            . '?width=' . self::IMAGE_SIZE
            . '&height=' . self::IMAGE_SIZE
            . '&nologo=true';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: image/*'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException('Image generation request failed: ' . ($error ?: 'unknown error'));
        }
        if ($status !== 200 || $body === '') {
            throw new \RuntimeException('Image generation returned HTTP ' . $status);
        }
        return (string)$body;
    }

    // Tries the self-hosted rembg service first (if configured) since it's
    // free and under our own control; falls back to remove.bg on any
    // failure there (not configured, timeout, non-200, crash-looping free
    // Render instance -- rembg-service/ runs on Render's free tier, which is
    // not always instantly available). If rembg isn't configured at all,
    // goes straight to remove.bg. Throws only if the attempted path(s) all
    // fail, with both errors included so a real remove.bg failure isn't
    // masked by an unrelated rembg one.
    private static function removeBackground(string $imageBytes, array $config): string
    {
        $rembgUrl = (string)($config['REMBG_SERVICE_URL'] ?? '');
        $rembgSecret = (string)($config['REMBG_SHARED_SECRET'] ?? '');
        $removeBgKey = (string)($config['REMOVEBG_API_KEY'] ?? '');

        $rembgError = null;
        if ($rembgUrl !== '') {
            try {
                return self::removeBackgroundViaRembg($imageBytes, $rembgUrl, $rembgSecret);
            } catch (\RuntimeException $e) {
                $rembgError = $e->getMessage();
                if ($removeBgKey === '') {
                    throw new \RuntimeException('rembg failed and no remove.bg fallback is configured: ' . $rembgError);
                }
            }
        }

        if ($removeBgKey === '') {
            // Only reachable if assertBackgroundRemovalConfigured() somehow
            // let an inconsistent state through -- defensive, not expected.
            throw new \RuntimeException('No background removal method configured.');
        }

        try {
            return self::removeBackgroundViaRemoveBg($imageBytes, $removeBgKey);
        } catch (\RuntimeException $e) {
            $message = $rembgError !== null
                ? 'Both background removal methods failed. rembg: ' . $rembgError . ' | remove.bg: ' . $e->getMessage()
                : $e->getMessage();
            throw new \RuntimeException($message);
        }
    }

    private static function removeBackgroundViaRembg(string $imageBytes, string $serviceUrl, string $sharedSecret): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'sbicon_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Could not allocate a temp file for upload');
        }
        file_put_contents($tmpPath, $imageBytes);

        try {
            $headers = ['Accept: image/*'];
            if ($sharedSecret !== '') $headers[] = 'X-Shared-Secret: ' . $sharedSecret;

            $ch = curl_init($serviceUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => ['image' => new \CURLFile($tmpPath, 'image/jpeg', 'icon.jpg')],
                CURLOPT_HTTPHEADER     => $headers,
                // Free-tier instance can be cold-starting after idling --
                // worth a longer wait than remove.bg's hosted API gets.
                CURLOPT_TIMEOUT        => 45,
            ]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
        } finally {
            @unlink($tmpPath);
        }

        if ($response === false || $error !== '') {
            throw new \RuntimeException('rembg service request failed: ' . ($error ?: 'unknown error'));
        }
        if ($status !== 200) {
            throw new \RuntimeException('rembg service returned HTTP ' . $status);
        }
        return (string)$response;
    }

    private static function removeBackgroundViaRemoveBg(string $imageBytes, string $apiKey): string
    {
        // CURLFile (not CURLStringFile, which needs PHP 8.1+) requires a real
        // path on disk -- write the fetched image to a temp file for the
        // duration of the upload.
        $tmpPath = tempnam(sys_get_temp_dir(), 'sbicon_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Could not allocate a temp file for upload');
        }
        file_put_contents($tmpPath, $imageBytes);

        try {
            $ch = curl_init('https://api.remove.bg/v1.0/removebg');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'image_file' => new \CURLFile($tmpPath, 'image/png', 'icon.png'),
                    'size'       => 'auto',
                    'format'     => 'png',
                    'type'       => 'icon',
                ],
                CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $apiKey],
                CURLOPT_TIMEOUT        => 30,
            ]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
        } finally {
            @unlink($tmpPath);
        }

        if ($response === false || $error !== '') {
            throw new \RuntimeException('Background removal request failed: ' . ($error ?: 'unknown error'));
        }
        if ($status !== 200) {
            $message = 'remove.bg returned HTTP ' . $status;
            $decoded = json_decode((string)$response, true);
            if (is_array($decoded) && !empty($decoded['errors'][0]['title'])) {
                $message .= ': ' . $decoded['errors'][0]['title'];
            }
            throw new \RuntimeException($message);
        }
        return (string)$response;
    }
}

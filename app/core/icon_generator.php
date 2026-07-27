<?php

declare(strict_types=1);

namespace SupaBein;

// Generates a single icon-style PNG asset on demand: fetches an image from
// CogView-4 (Zhipu, config['ZHIPU_API_KEY']) if configured, falling back to
// PollinationsClient (free, keyless text-to-image) on any failure there --
// then cuts its background out via real ML segmentation, trying the
// self-hosted rembg service first (config['REMBG_SERVICE_URL'] /
// config['REMBG_SHARED_SECRET'], a small Flask+rembg container on Render,
// see rembg-service/ at the repo root), falling back to remove.bg's API
// (config['REMOVEBG_API_KEY']) if rembg isn't configured or the request
// fails for any reason. At least one of the two background-removal methods
// must be configured.
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
    // CogView-4 asks for at least 1024x1024 -- generated at that size, then
    // downscaled after the watermark crop below so IMAGE_SIZE stays the one
    // true output dimension for both providers.
    private const COGVIEW_IMAGE_SIZE = 1024;
    // Live-tested (2026-07): every CogView-4 image on this account tier
    // comes back with a fixed "AI生成" watermark badge stamped in the
    // bottom-right corner -- roughly the last ~20% of width and ~9% of
    // height. Cropping the bottom BAND off (full width, not just the corner)
    // guarantees the watermark is gone regardless of its exact horizontal
    // position within that band, without producing an off-center or
    // asymmetric result the way a corner-only crop would. The icon prompt
    // already asks for a centered subject with generous margin, so losing
    // this bottom sliver doesn't crop into the subject itself.
    private const COGVIEW_WATERMARK_CROP_FRACTION = 0.12;

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
        $raw = self::fetchSourceImage($prompt);
        return self::removeBackground($raw, $config);
    }

    // CogView-4 first (config-gated, better rendering quality -- see the
    // generate() docblock); Pollinations as the always-available fallback so
    // a missing key or a transient CogView failure never blocks generation
    // outright, same graceful-degradation posture as removeBackground()'s
    // own rembg-then-remove.bg chain right below it.
    private static function fetchSourceImage(string $prompt): string
    {
        $config = \App::get('config');
        $zhipuKey = (string)($config['ZHIPU_API_KEY'] ?? '');
        if ($zhipuKey !== '') {
            try {
                return self::fetchFromCogView($prompt, $zhipuKey);
            } catch (\RuntimeException $e) {
                // Falls through to Pollinations below.
            }
        }
        return \SupaBein\PollinationsClient::generate($prompt, self::IMAGE_SIZE);
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

    // Calls Zhipu's CogView-4 image-generation endpoint (returns a
    // short-lived signed URL, not the image bytes directly -- fetched as a
    // second request), then crops the bottom watermark band off before
    // handing the bytes on to background removal. Downscales to
    // IMAGE_SIZE afterward so both source providers hand removeBackground()
    // the same target resolution.
    private static function fetchFromCogView(string $prompt, string $apiKey): string
    {
        $body = json_encode([
            'model'  => 'cogview-4',
            'prompt' => $prompt,
            'size'   => self::COGVIEW_IMAGE_SIZE . 'x' . self::COGVIEW_IMAGE_SIZE,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init('https://open.bigmodel.cn/api/paas/v4/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new \RuntimeException('CogView-4 request failed: ' . ($error ?: 'unknown error'));
        }
        if ($status !== 200) {
            throw new \RuntimeException('CogView-4 returned HTTP ' . $status . ': ' . substr((string)$response, 0, 300));
        }
        $envelope = json_decode((string)$response, true);
        $imageUrl = $envelope['data'][0]['url'] ?? null;
        if (!is_string($imageUrl) || $imageUrl === '') {
            throw new \RuntimeException('CogView-4 response did not include an image URL');
        }

        $ch = curl_init($imageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $imageBytes = curl_exec($ch);
        $imgStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $imgError = curl_error($ch);
        curl_close($ch);

        if ($imageBytes === false || $imgError !== '' || $imgStatus !== 200 || $imageBytes === '') {
            throw new \RuntimeException('Could not download CogView-4 image: ' . ($imgError ?: ('HTTP ' . $imgStatus)));
        }

        return self::cropWatermarkBand((string)$imageBytes);
    }

    // Crops the bottom COGVIEW_WATERMARK_CROP_FRACTION of the image off
    // (full width) to remove the corner watermark, then resizes back down
    // to a square IMAGE_SIZE x IMAGE_SIZE canvas -- background removal and
    // every downstream consumer of this class already expect that fixed
    // square shape (see the pre-existing Pollinations path, which requests
    // it directly). Falls back to returning the ORIGINAL, uncropped bytes
    // on any GD failure (corrupt image, unsupported format) rather than
    // blocking generation entirely over a cosmetic watermark -- the
    // subsequent background-removal step still runs either way.
    private static function cropWatermarkBand(string $imageBytes): string
    {
        $src = @imagecreatefromstring($imageBytes);
        if ($src === false) {
            return $imageBytes;
        }
        try {
            $width = imagesx($src);
            $height = imagesy($src);
            $keepHeight = (int)round($height * (1 - self::COGVIEW_WATERMARK_CROP_FRACTION));
            if ($keepHeight < 1 || $width < 1) {
                return $imageBytes;
            }

            $cropped = imagecreatetruecolor($width, $keepHeight);
            imagesavealpha($cropped, true);
            $transparent = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
            imagefill($cropped, 0, 0, $transparent);
            imagecopy($cropped, $src, 0, 0, 0, 0, $width, $keepHeight);

            $final = imagecreatetruecolor(self::IMAGE_SIZE, self::IMAGE_SIZE);
            imagesavealpha($final, true);
            $finalTransparent = imagecolorallocatealpha($final, 0, 0, 0, 127);
            imagefill($final, 0, 0, $finalTransparent);
            imagecopyresampled(
                $final, $cropped, 0, 0, 0, 0,
                self::IMAGE_SIZE, self::IMAGE_SIZE, $width, $keepHeight
            );

            ob_start();
            imagepng($final);
            $out = ob_get_clean();
            imagedestroy($cropped);
            imagedestroy($final);
            return $out !== false && $out !== '' ? $out : $imageBytes;
        } finally {
            imagedestroy($src);
        }
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

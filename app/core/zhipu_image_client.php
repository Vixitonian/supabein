<?php

declare(strict_types=1);

namespace SupaBein;

// Zhipu / BigModel's CogView-4 image-generation endpoint. Extracted out of
// FlyGen's icon_generator.php so any AI Assistant with an "image" kind can
// use it, not just FlyGen's own icon feature -- see
// Catalog::callAiAssistantImage().
class ZhipuImageClient
{
    private const ENDPOINT = 'https://open.bigmodel.cn/api/paas/v4/images/generations';
    private const IMAGE_SIZE = 1024;
    // Live-tested (2026-07): every CogView-4 image on this account tier
    // comes back with a fixed "AI生成" watermark badge stamped in the
    // bottom-right corner -- roughly the last ~20% of width and ~9% of
    // height. Cropping the bottom BAND off (full width, not just the corner)
    // guarantees the watermark is gone regardless of its exact horizontal
    // position within that band, without producing an off-center or
    // asymmetric result the way a corner-only crop would.
    private const WATERMARK_CROP_FRACTION = 0.12;

    // Returns raw PNG bytes, already cropped clear of the watermark and
    // resized back to a square IMAGE_SIZE x IMAGE_SIZE canvas. $model is one
    // of AI_IMAGE_ALLOWED_MODELS['zhipu'] (cogview-3-flash, cogview-4) --
    // both live-tested (2026-07) and both carry the identical bottom-right
    // "AI生成" watermark badge, so the same crop applies to either.
    public static function generate(string $apiKey, string $prompt, string $model = 'cogview-3-flash'): string
    {
        $body = json_encode([
            'model'  => $model,
            'prompt' => $prompt,
            'size'   => self::IMAGE_SIZE . 'x' . self::IMAGE_SIZE,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init(self::ENDPOINT);
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
            throw new \RuntimeException("Zhipu $model request failed: " . ($error ?: 'unknown error'));
        }
        if ($status !== 200) {
            throw new \RuntimeException("Zhipu $model returned HTTP $status: " . substr((string)$response, 0, 300));
        }
        $envelope = json_decode((string)$response, true);
        $imageUrl = $envelope['data'][0]['url'] ?? null;
        if (!is_string($imageUrl) || $imageUrl === '') {
            throw new \RuntimeException("Zhipu $model response did not include an image URL");
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
            throw new \RuntimeException("Could not download Zhipu $model image: " . ($imgError ?: ('HTTP ' . $imgStatus)));
        }

        return self::cropWatermarkBand((string)$imageBytes);
    }

    // Crops the bottom WATERMARK_CROP_FRACTION of the image off (full
    // width) to remove the corner watermark, then resizes back down to a
    // square IMAGE_SIZE x IMAGE_SIZE canvas. Falls back to returning the
    // ORIGINAL, uncropped bytes on any GD failure (corrupt image,
    // unsupported format) rather than blocking generation entirely over a
    // cosmetic watermark.
    private static function cropWatermarkBand(string $imageBytes): string
    {
        $src = @imagecreatefromstring($imageBytes);
        if ($src === false) {
            return $imageBytes;
        }
        try {
            $width = imagesx($src);
            $height = imagesy($src);
            $keepHeight = (int)round($height * (1 - self::WATERMARK_CROP_FRACTION));
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
}

<?php

declare(strict_types=1);

namespace SupaBein;

// Pollinations.ai's free, keyless text-to-image endpoint. Extracted out of
// IconGenerator so it's a reusable client alongside ZhipuImageClient, not
// logic private to FlyGen's icon feature -- IconGenerator still uses it as
// its always-available fallback when CogView is unset or fails.
class PollinationsClient
{
    private const ENDPOINT = 'https://image.pollinations.ai/prompt/';

    public static function generate(string $prompt, int $size = 512): string
    {
        $url = self::ENDPOINT . rawurlencode($prompt)
            . '?width=' . $size
            . '&height=' . $size
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
}

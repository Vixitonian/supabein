<?php

declare(strict_types=1);



// ─── Reference file attachments (build/edit prompts) ─────────────────────────
// Lets a build/edit request carry real reference material (a logo to match
// exactly, a sample document/screenshot to build a schema or UI from)
// instead of relying entirely on the AI inventing plausible-looking
// placeholders from a text description alone.

// Binary types sent to the AI as true multimodal attachments (images/PDF —
// see ai_prepare_attachments_for_ai()); a .docx is unpacked server-side
// since no provider accepts it directly. Everything in
// AI_ATTACHMENT_TEXT_MIME is plain-text-ish and needs no extraction at all —
// its bytes ARE the content, just decoded and dropped straight into the
// prompt as context.
const AI_ATTACHMENT_BINARY_MIME = [
    'image/png', 'image/jpeg', 'image/webp', 'image/gif',
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
];
const AI_ATTACHMENT_TEXT_MIME = [
    'text/plain', 'text/markdown', 'text/html', 'text/csv', 'application/json',
];
const AI_ATTACHMENT_ALLOWED_MIME = [...AI_ATTACHMENT_BINARY_MIME, ...AI_ATTACHMENT_TEXT_MIME];

const AI_ATTACHMENT_MAX_COUNT       = 8;
const AI_ATTACHMENT_MAX_BYTES_EACH  = 15 * 1024 * 1024; // 15MB per file
const AI_ATTACHMENT_MAX_BYTES_TOTAL = 40 * 1024 * 1024; // 40MB combined
const AI_ATTACHMENT_TEXT_CHARS_CAP  = 12000; // per text/plain-ish file, in the prompt itself

/**
 * Validates and decodes the `attachments` field of an /v1/ai/build or
 * /v1/ai/edit request body:
 *   attachments: [{ filename, mime_type, data_base64 }, ...]
 * Aborts with 422 on anything invalid rather than silently dropping a file
 * the caller thinks was included.
 *
 * @return array<int, array{filename:string, mime_type:string, bytes:string}>
 */
function ai_validate_attachments($raw): array
{
    if ($raw === null || $raw === []) return [];
    if (!is_array($raw)) abort(422, 'attachments must be an array');
    if (count($raw) > AI_ATTACHMENT_MAX_COUNT) {
        abort(422, 'Too many attachments (max ' . AI_ATTACHMENT_MAX_COUNT . ')');
    }

    $out = [];
    $totalBytes = 0;
    foreach (array_values($raw) as $i => $item) {
        if (!is_array($item)) abort(422, "attachments[$i] must be an object");
        $mime = (string)($item['mime_type'] ?? '');
        $b64  = (string)($item['data_base64'] ?? '');
        $name = trim((string)($item['filename'] ?? '')) ?: "attachment-$i";
        if (!in_array($mime, AI_ATTACHMENT_ALLOWED_MIME, true)) {
            abort(422, "attachments[$i] (\"$name\"): unsupported file type \"$mime\" — allowed: PNG/JPEG/WEBP/GIF images, "
                . 'PDF, .docx, or plain text (txt/markdown/html/csv/json)');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            abort(422, "attachments[$i] (\"$name\"): data_base64 is missing or not valid base64");
        }
        if (strlen($bytes) > AI_ATTACHMENT_MAX_BYTES_EACH) {
            abort(422, "attachments[$i] (\"$name\") exceeds the " . (AI_ATTACHMENT_MAX_BYTES_EACH / 1024 / 1024) . 'MB per-file limit');
        }
        $totalBytes += strlen($bytes);
        if ($totalBytes > AI_ATTACHMENT_MAX_BYTES_TOTAL) {
            abort(422, 'Attachments exceed the ' . (AI_ATTACHMENT_MAX_BYTES_TOTAL / 1024 / 1024) . 'MB combined limit');
        }
        $out[] = ['filename' => $name, 'mime_type' => $mime, 'bytes' => $bytes];
    }
    return $out;
}

/**
 * Unpacks a .docx (it's a zip archive) to pull out its embedded media
 * (logos, watermarks, photos — word/media/*) as images, and its visible
 * paragraph text (word/document.xml's <w:t> runs) as plain text. Word text
 * boxes (<w:txbxContent>) aren't specially walked — some real-world letter
 * templates carry their actual body copy inside one, which this can't see
 * any more than the schema/frontend prompts could reason about full OOXML
 * layout either way — but the embedded images (the part that actually
 * matters most for visual fidelity: logos, watermarks, letterhead art) are
 * always plain files in the zip regardless of whether they're referenced
 * from a text box or the main document body, so those are never missed.
 *
 * @return array{images: array<int, array{media_type:string, data_base64:string}>, text: string}
 */
function ai_extract_docx(string $bytes, string $filename): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'sb_docx_');
    file_put_contents($tmp, $bytes);

    $images = [];
    $text   = '';
    $zip = new \ZipArchive();
    if ($zip->open($tmp) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || count($images) >= 6) continue;
            if (!preg_match('#^word/media/[^/]+\.(png|jpe?g|gif|webp)$#i', $entry, $m)) continue;
            $data = $zip->getFromIndex($i);
            if ($data === false) continue;
            $mime = match (strtolower($m[1])) {
                'png'          => 'image/png',
                'jpg', 'jpeg'  => 'image/jpeg',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
                default        => null,
            };
            if ($mime === null) continue;
            $images[] = ['media_type' => $mime, 'data_base64' => base64_encode($data)];
        }
        $docXml = $zip->getFromName('word/document.xml');
        if ($docXml !== false && preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $docXml, $m)) {
            $text = trim(implode(' ', array_map(
                fn($s) => html_entity_decode($s, ENT_QUOTES | ENT_XML1),
                $m[1]
            )));
            $text = mb_substr($text, 0, AI_ATTACHMENT_TEXT_CHARS_CAP);
        }
        $zip->close();
    }
    @unlink($tmp);

    return ['images' => $images, 'text' => $text];
}

/**
 * Turns validated request attachments into what the AI clients can actually
 * consume:
 *   - images/PDF pass straight through as multimodal attachments (so the AI
 *     can SEE them — match colors, read a layout, etc.)
 *   - a directly-uploaded image is ALSO persisted as a real project asset
 *     (see below) so it can be used as-is (a logo, a photo) rather than
 *     just looked at for inspiration
 *   - .docx has no direct multimodal API support anywhere, so it's unpacked
 *     instead (see ai_extract_docx()) — its embedded images are treated as
 *     reference material only, not uploaded as standalone assets, since an
 *     image buried inside a reference document is rarely "the logo" itself
 *   - plain-text-ish files (txt/markdown/html/csv/json) need no extraction
 *     at all — their bytes ARE the content, decoded as UTF-8 and dropped
 *     straight into the prompt as context, capped per-file so one huge
 *     upload can't blow out the whole prompt
 *
 * Persisting an uploaded image as a real asset needs a project to store it
 * under. For an edit, $projectId is already known, so it's uploaded
 * immediately and the AI is told its real URL. For a fresh build, no
 * project exists yet — the file is staged in the returned 'pending_assets'
 * list instead, and the AI is told the URL it WILL have (using the same
 * __SB_PID__ placeholder ai_deploy_files() already substitutes into every
 * deployed file), so the caller can actually write the bytes once
 * ai_execute_build() creates the real project (see ai_run_build_and_deploy()).
 *
 * @param array<int, array{filename:string, mime_type:string, bytes:string}> $validated
 * @return array{
 *   attachments: array<int, array{media_type:string, data_base64:string}>,
 *   context: string,
 *   pending_assets: array<int, array{filename:string, bytes:string}>
 * }
 */
function ai_prepare_attachments_for_ai(array $validated, ?int $projectId = null): array
{
    $attachments    = [];
    $contextParts   = [];
    $pendingAssets  = [];
    $usedNames      = [];
    $assetLines     = [];

    foreach ($validated as $item) {
        if ($item['mime_type'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            $extracted = ai_extract_docx($item['bytes'], $item['filename']);
            foreach ($extracted['images'] as $img) $attachments[] = $img;
            if ($extracted['text'] !== '') {
                $contextParts[] = "--- Text extracted from \"{$item['filename']}\" ---\n" . $extracted['text'];
            }
            continue;
        }

        if (in_array($item['mime_type'], AI_ATTACHMENT_TEXT_MIME, true)) {
            $text = mb_substr(
                mb_convert_encoding($item['bytes'], 'UTF-8', 'UTF-8, ISO-8859-1'),
                0,
                AI_ATTACHMENT_TEXT_CHARS_CAP
            );
            if (trim($text) !== '') {
                $contextParts[] = "--- Contents of \"{$item['filename']}\" ({$item['mime_type']}) ---\n" . $text;
            }
            continue;
        }

        $attachments[] = ['media_type' => $item['mime_type'], 'data_base64' => base64_encode($item['bytes'])];

        if (str_starts_with($item['mime_type'], 'image/')) {
            $assetName = ai_dedupe_asset_filename($item['filename'], $item['mime_type'], $usedNames);
            if ($projectId !== null) {
                $stored = \SupaBein\Storage::putBytes($projectId, 'assets', $assetName, $item['bytes']);
                $assetLines[] = "- \"{$item['filename']}\" is now stored at: {$stored['url']}";
            } else {
                $pendingAssets[] = ['filename' => $assetName, 'bytes' => $item['bytes']];
                $assetLines[] = "- \"{$item['filename']}\" WILL be stored at: /api/v1/storage/__SB_PID__/assets/{$assetName} "
                    . '(write that exact literal path — including __SB_PID__ verbatim — into your generated code; '
                    . 'it is substituted with the real project ID automatically at deploy time)';
            }
        }
    }

    if ($assetLines) {
        $contextParts[] = "--- Uploaded image files (real, working assets — not just visual reference) ---\n"
            . implode("\n", $assetLines)
            . "\nIf the request implies using one of these directly (e.g. \"use this as the logo\"), reference "
            . 'its exact URL above in your generated code (an <img> src, a CSS background-image, etc.) instead '
            . 'of inventing a placeholder image or a different source.';
    }

    return ['attachments' => $attachments, 'context' => implode("\n\n", $contextParts), 'pending_assets' => $pendingAssets];
}

/** Sanitizes an original filename into a safe, collision-free asset filename within one request's scope. */
function ai_dedupe_asset_filename(string $originalFilename, string $mimeType, array &$usedNames): string
{
    $ext = match ($mimeType) {
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'bin',
    };
    $base = strtolower(pathinfo($originalFilename, PATHINFO_FILENAME));
    $base = trim(preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '', '-');
    if ($base === '') $base = 'asset';

    $name = "{$base}.{$ext}";
    $i = 2;
    while (isset($usedNames[$name])) {
        $name = "{$base}-{$i}.{$ext}";
        $i++;
    }
    $usedNames[$name] = true;
    return $name;
}

/**
 * Writes out the images ai_prepare_attachments_for_ai() staged as
 * 'pending_assets' (uploaded before a project existed) now that a real
 * project ID is available — see the doc comment on ai_prepare_attachments_for_ai().
 *
 * @param array<int, array{filename:string, bytes:string}> $pendingAssets
 */
function ai_upload_pending_assets(array $pendingAssets, int $projectId): void
{
    foreach ($pendingAssets as $asset) {
        try {
            \SupaBein\Storage::putBytes($projectId, 'assets', $asset['filename'], $asset['bytes']);
        } catch (\Throwable $e) {
            // Best-effort — a failed asset write shouldn't fail the whole
            // build/apply when the schema, tables, and rest of the frontend
            // already deployed successfully. The generated code's __SB_PID__
            // URL just 404s for this one file if this happens.
            sb_log('ai_build', 'Pending asset upload failed: ' . $e->getMessage(), ['project_id' => $projectId, 'filename' => $asset['filename']]);
        }
    }
}

/** Appended to a system prompt only when the request actually carries attachments. */
function ai_attachment_instruction_note(): string
{
    return "\n\nATTACHMENTS: One or more reference files were uploaded with this request (attached as images/"
        . 'documents, and/or extracted as text below). Extract concrete details from them — exact colors, '
        . 'logos, layout, field names, exact wording — and use those details directly instead of inventing '
        . 'generic placeholders. Do not describe the attachments back to the user; just build to match them.';
}

/**
 * Validates `attachments` from a job-creating route body and re-encodes it
 * into the plain-JSON shape a job's LONGTEXT payload column can hold (the
 * raw decoded bytes ai_validate_attachments() returns aren't JSON-safe).
 * The worker reverses this via ai_job_payload_refs() right before it
 * actually needs the attachments.
 */
function ai_validate_attachments_for_job($raw): array
{
    return array_map(
        fn($a) => ['filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'data_base64' => base64_encode($a['bytes'])],
        ai_validate_attachments($raw)
    );
}

/** Reverses ai_validate_attachments_for_job() and runs ai_prepare_attachments_for_ai() on the result — call once per job in the worker. */
function ai_job_payload_refs(array $payload, ?int $projectId = null): array
{
    $attachments = array_map(
        fn($a) => ['filename' => $a['filename'] ?? '', 'mime_type' => $a['mime_type'] ?? '', 'bytes' => base64_decode($a['data_base64'] ?? '')],
        $payload['attachments'] ?? []
    );
    return ai_prepare_attachments_for_ai($attachments, $projectId);
}
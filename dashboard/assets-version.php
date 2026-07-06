<?php
// Shared by index.php (per-file ?v= query params) and sw.php (one combined
// CACHE_NAME) so both derive asset versions from the exact same source of
// truth. Content-hash based rather than filemtime()-based: a hash changes
// if and only if the file's actual bytes changed, independent of how it got
// onto disk (git checkout, rsync, a container COPY) — mtime is only a proxy
// for "did this change", and deploy mechanics can get it wrong in either
// direction (touching a file without changing it leaves old content behind
// a new URL forever; copying unchanged content with a fresh mtime bumps the
// cache for no reason). A short hash is exact either way.
function sb_file_hash(string $path): string
{
    if (!is_file($path)) return '0';
    $hash = @md5_file($path);
    return $hash !== false ? substr($hash, 0, 10) : '0';
}

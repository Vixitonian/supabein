<?php
/**
 * Standalone router for the wildcard vhost (*.dxinnovationhub.com).
 *
 * Deliberately independent of the main SupaBein app: it never requires
 * app/bootstrap.php (so a broken deploy or a syntax error in some unrelated
 * route file can't take every hosted subdomain down with it) and it never
 * queries SupaBein's own `sites`/`projects` tables directly. Instead it
 * reads only the neutral `site_registry` table, which SupaBein (or any
 * other product) writes into via POST /v1/projects/:id/hostnames -- this
 * file doesn't know or care who wrote a given row.
 *
 * It DOES still read config/secrets.php for DB credentials rather than
 * duplicating them into a second file -- a deliberate middle ground: this
 * still depends on that one file surviving, but no longer on the rest of
 * the app (vendor/, composer autoload, every other route file) being
 * intact. See the "put back site-serve.php" discussion for why full
 * independence (its own copy of the credentials) was left as optional
 * further hardening rather than done up front.
 */

declare(strict_types=1);

$secretsPath = '/home/dxinethn/supabein.dxinnovationhub.com/config/secrets.php';
$config = require $secretsPath;

// Every deployed app calls its own API as `window.location.origin + '/api/v1'`
// -- that only ever worked because every site used to share one domain with
// the real API. Now that a site can be reached at its own subdomain/custom
// domain, that origin is THIS wildcard vhost, which has no API of its own --
// live-caught as every data call silently falling through to the SPA
// fallback and getting index.html's HTML back instead of JSON ("Unexpected
// token '<'"). Proxying /api/ here, transparently, fixes it for every
// existing and future deployed app without editing a single one of them.
$reqPathRaw = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
if (str_starts_with($reqPathRaw, '/api/')) {
    $target = rtrim($config['API_BASE_URL'], '/') . $_SERVER['REQUEST_URI'];

    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_') && $k !== 'HTTP_HOST') {
            $headers[] = str_replace('_', '-', substr($k, 5)) . ': ' . $v;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];

    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $_SERVER['REQUEST_METHOD'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        // AI-assistant chat calls can legitimately run past 30s once they're
        // several tiers deep into the provider fallback chain (a slow/rate-
        // limited primary timing out before the next candidate is tried) --
        // 30s was turning those into a false 502 "Upstream API unreachable"
        // here, well before the backend's own request actually finished.
        CURLOPT_TIMEOUT        => 300,
    ]);
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Upstream API unreachable']);
        exit;
    }
    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    http_response_code($status);
    foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
        // Only forward headers the client actually needs -- skip hop-by-hop
        // and framing headers (Transfer-Encoding etc.) that don't apply to
        // this second response, and skip Content-Length since PHP recomputes
        // it correctly for whatever we echo below regardless.
        if (preg_match('/^(Content-Type|X-Refresh-Token|Cache-Control|ETag):/i', $line)) {
            header(trim($line));
        }
    }
    echo substr($raw, $headerSize);
    exit;
}

try {
    $pdo = new PDO(
        $config['DB_DSN'],
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('[site-server] DB CONNECTION FAILED: ' . $e->getMessage());
    http_response_code(503);
    echo 'Service unavailable';
    exit;
}

$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);

$stmt = $pdo->prepare('SELECT docroot, spa_mode, project_id FROM site_registry WHERE hostname = ?');
$stmt->execute([$host]);
$registration = $stmt->fetch();

if (!$registration) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$base    = rtrim($registration['docroot'], '/');
$spaMode = (int)$registration['spa_mode'] === 1;

$reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$reqPath = ltrim($reqPath, '/');

// Normalize trailing slash -> index.html (same convention as site-serve.php)
if ($reqPath === '' || str_ends_with($reqPath, '/')) {
    $reqPath = rtrim($reqPath, '/') . '/index.html';
}

// Path traversal guard -- resolve symlinks/.. manually since realpath()
// would fail on a not-yet-existing file (the SPA-fallback case below).
function site_server_normalize_path(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    return '/' . implode('/', $parts);
}

$fullPath = site_server_normalize_path($base . '/' . $reqPath);
if ($fullPath !== $base && !str_starts_with($fullPath, $base . '/')) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

// ─── Bot-visible meta tag injection (registered via
// app/routes/meta_resolver_routes.php, POST /v1/projects/:id/meta-resolvers) ──
//
// Short, well-known, stable list of link-preview crawler User-Agents --
// deliberately not exhaustive, just the common ones. A plain substring
// match is enough; these UAs don't vary in casing/formatting in practice.
const SITE_SERVER_CRAWLER_UA_NEEDLES = [
    'whatsapp', 'facebookexternalhit', 'twitterbot', 'slackbot', 'discordbot',
    'linkedinbot', 'telegrambot', 'redditbot', 'applebot', 'pinterest',
    'skypeuripreview', 'vkshare', 'embedly', 'iframely', 'quora link preview',
    'w3c_validator',
];

function site_server_is_crawler(string $userAgent): bool
{
    $ua = strtolower($userAgent);
    foreach (SITE_SERVER_CRAWLER_UA_NEEDLES as $needle) {
        if (str_contains($ua, $needle)) return true;
    }
    return false;
}

// Standalone duplicate of Catalog::resolveDotPath()/resolveValueSpec() --
// this file deliberately never depends on the main app (see the top-of-file
// docblock), so the same tiny declarative-spec resolver is re-implemented
// here rather than requiring app/catalog/catalog.php.
function site_server_resolve_dot_path(array $ctx, string $path): mixed
{
    $cursor = $ctx;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($cursor) || !array_key_exists($seg, $cursor)) return null;
        $cursor = $cursor[$seg];
    }
    return $cursor;
}

function site_server_resolve_value_spec(array $spec, array $ctx): mixed
{
    if (array_key_exists('literal', $spec)) return $spec['literal'];
    if (array_key_exists('path', $spec)) return site_server_resolve_dot_path($ctx, (string)$spec['path']);
    if (array_key_exists('template', $spec)) {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($ctx) {
            $v = site_server_resolve_dot_path($ctx, $m[1]);
            if ($v === null) return '';
            return is_scalar($v) ? (string)$v : json_encode($v);
        }, (string)$spec['template']);
    }
    return null;
}

function site_server_resolve_value_spec_with_fallback(array $spec, array $ctx): mixed
{
    $val = site_server_resolve_value_spec($spec, $ctx);
    if (($val === null || $val === '') && isset($spec['fallback']) && is_array($spec['fallback'])) {
        return site_server_resolve_value_spec($spec['fallback'], $ctx);
    }
    return $val;
}

// Matches a registered "/store/:slug/*" style pattern against the real
// request path, the same ":name" capture-group convention app/router.php
// already uses -- kept identical on purpose so one syntax works everywhere
// on the platform. A trailing "/*" matches (and discards) anything after
// that point in the path. Returns the captured params, or null if no match.
function site_server_match_path_pattern(string $pattern, string $path): ?array
{
    $regex = preg_replace_callback('/:([a-zA-Z_][a-zA-Z0-9_]*)/', fn($m) => '(?P<' . $m[1] . '>[^/]+)', $pattern);
    if (str_ends_with($regex, '/*')) {
        $regex = substr($regex, 0, -2) . '(?:/.*)?';
    }
    if (!preg_match('#^' . $regex . '$#', $path, $m)) {
        return null;
    }
    $params = [];
    foreach ($m as $k => $v) {
        if (is_string($k)) $params[$k] = $v;
    }
    return $params;
}

// Ranks resolvers so a request matching more than one (e.g. a hostname
// catch-all "/*" AND a path-specific "/store/:slug/*") resolves
// deterministically instead of depending on registration/row order --
// requested after the hostname-lookup gap below made that ambiguity a real
// case, not just a hypothetical one. Tier 0 is any hostname-only catch-all
// ("/*" or "/"); everything else is tier 1+, ranked by how many literal
// (non-":param") path segments it has, then by raw pattern length --
// "/store/:slug/*" (1 literal segment) beats "/*" (tier 0), and
// "/store/:slug/reviews" would beat "/store/:slug/*" if both existed. A
// deliberately simple heuristic, not a full route-precedence engine.
function site_server_meta_resolver_specificity(string $pattern): array
{
    $isCatchAll = ($pattern === '/*' || $pattern === '/');
    $segments = array_values(array_filter(explode('/', trim($pattern, '/')), fn($s) => $s !== '' && $s !== '*'));
    $literalCount = 0;
    foreach ($segments as $seg) {
        if (!str_starts_with($seg, ':')) $literalCount++;
    }
    return [$isCatchAll ? 0 : 1, $literalCount, strlen($pattern)];
}

// Finds the highest-precedence meta_resolver (registered for this
// hostname's project, see site_server_meta_resolver_specificity() above)
// whose path_pattern matches the real request path, looks up the row it
// points at, and returns the resulting HTML with <title>/<meta> tags
// injected -- or null if nothing matched or the looked-up row doesn't
// exist, in which case the caller falls through to serving the file
// completely unmodified.
function site_server_render_crawler_meta(PDO $pdo, int $projectId, string $reqPath, string $host, string $indexPath): ?string
{
    $stmt = $pdo->prepare('SELECT path_pattern, lookup_json, meta_json FROM meta_resolvers WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $resolvers = $stmt->fetchAll();
    if (!$resolvers) return null;

    usort($resolvers, fn($a, $b) => site_server_meta_resolver_specificity($b['path_pattern']) <=> site_server_meta_resolver_specificity($a['path_pattern']));

    foreach ($resolvers as $resolver) {
        $params = site_server_match_path_pattern($resolver['path_pattern'], $reqPath);
        if ($params === null) continue;

        $lookup = json_decode($resolver['lookup_json'], true);
        $meta   = json_decode($resolver['meta_json'], true);
        if (!is_array($lookup) || !is_array($meta)) continue;

        // "host"/"host.label" are reserved lookup sources, not path
        // captures -- resolve them directly from the Host header this
        // request actually came in on, rather than through params (there
        // may be no path segments to capture from at all, e.g. a bare
        // subdomain-root request). Everything else still goes through the
        // normal params.* dot-path resolution.
        $matchPath = (string)($lookup['match_value_path'] ?? '');
        if ($matchPath === 'host') {
            $matchValue = $host;
        } elseif ($matchPath === 'host.label') {
            $matchValue = explode('.', $host)[0] ?? $host;
        } else {
            $matchValue = site_server_resolve_dot_path(['params' => $params], $matchPath);
        }
        if ($matchValue === null || $matchValue === '') continue;

        $tableStmt = $pdo->prepare('SELECT physical_name FROM project_tables WHERE project_id = ? AND table_name = ?');
        $tableStmt->execute([$projectId, (string)($lookup['table'] ?? '')]);
        $physicalName = $tableStmt->fetchColumn();
        if (!$physicalName) continue;

        // match_column was validated against the project's real columns at
        // registration time (see meta_resolver_routes.php) -- this regex
        // check is defense in depth, not the primary safeguard.
        $matchColumn = (string)($lookup['match_column'] ?? '');
        if ($matchColumn === '' || !preg_match('/^[A-Za-z0-9_]+$/', $matchColumn)) continue;

        $rowStmt = $pdo->prepare('SELECT * FROM `' . $physicalName . '` WHERE `' . $matchColumn . '` = ? LIMIT 1');
        $rowStmt->execute([$matchValue]);
        $row = $rowStmt->fetch();
        if (!$row) continue;

        $html = file_get_contents($indexPath);
        if ($html === false) return null;

        $ctx = ['row' => $row, 'params' => $params];
        foreach ($meta as $key => $spec) {
            if (!is_array($spec)) continue;
            $value = site_server_resolve_value_spec_with_fallback($spec, $ctx);
            if ($value === null || $value === '') continue;
            $escaped = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

            if ($key === 'title') {
                $html = preg_match('#<title>.*?</title>#is', $html)
                    ? preg_replace('#<title>.*?</title>#is', '<title>' . $escaped . '</title>', $html, 1)
                    : preg_replace('#</head>#i', '<title>' . $escaped . '</title></head>', $html, 1);
                continue;
            }

            $attr = (str_starts_with($key, 'og:') || str_starts_with($key, 'twitter:')) ? 'property' : 'name';
            $tag  = '<meta ' . $attr . '="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" content="' . $escaped . '">';
            $html = preg_replace('#</head>#i', $tag . '</head>', $html, 1);
        }

        return $html;
    }

    return null;
}

// SPA fallback: if the exact file isn't there and this site opted into it,
// serve index.html instead so client-side routing survives a hard refresh.
if (!file_exists($fullPath) || is_dir($fullPath)) {
    if ($spaMode) {
        $fullPath = $base . '/index.html';
    }
    if (!file_exists($fullPath)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
}

// Bot-visible meta tag injection: WhatsApp/Facebook/Twitter/Slack/etc. link
// previews fetch a URL with a plain HTTP client and read the static HTML
// only -- they never execute the deployed app's own client-side
// document-title/meta-tag logic. Without this, every shared link previews
// as whatever generic fallback is baked into the app's index.html,
// regardless of which page was actually shared. Only runs for a known
// crawler requesting an HTML document (never for a real asset request, and
// never for a normal human visitor -- zero behavior/perf change for them);
// see app/routes/meta_resolver_routes.php for registration and
// site_server_render_crawler_meta() below for the actual lookup+injection.
if (
    strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && str_ends_with($fullPath, '/index.html')
    && !empty($registration['project_id'])
    && site_server_is_crawler($_SERVER['HTTP_USER_AGENT'] ?? '')
) {
    $crawlerHtml = site_server_render_crawler_meta($pdo, (int)$registration['project_id'], $reqPathRaw, $host, $fullPath);
    if ($crawlerHtml !== null) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        // Never cache a bot-tailored response -- the next request to this
        // exact URL could be a different business's crawler hit, or a real
        // human browser that must get the unmodified SPA shell, not a
        // stale copy of whichever business this particular bot request
        // happened to resolve to.
        header('Cache-Control: no-store');
        echo $crawlerHtml;
        exit;
    }
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

$mimes = [
    'html' => 'text/html; charset=utf-8',
    'htm'  => 'text/html; charset=utf-8',
    'css'  => 'text/css',
    'js'   => 'application/javascript',
    'mjs'  => 'application/javascript',
    'json' => 'application/json',
    'xml'  => 'application/xml',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'ico'  => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'ttf'  => 'font/ttf',
    'otf'  => 'font/otf',
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mp3'  => 'audio/mpeg',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

// Same freshness policy as site-serve.php -- deployed apps reference their
// own assets with plain relative paths and no cache-busting query strings,
// so every response must revalidate rather than rely on a flat max-age.
$mtime = filemtime($fullPath);
$size  = filesize($fullPath);
$etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

header('Content-Type: ' . $mime);
header('Cache-Control: no-cache, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifModSince  = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
$notModified = false;
if ($ifNoneMatch !== '') {
    $notModified = ($ifNoneMatch === $etag);
} elseif ($ifModSince !== false) {
    $notModified = ($ifModSince >= $mtime);
}
if ($notModified) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . $size);
readfile($fullPath);

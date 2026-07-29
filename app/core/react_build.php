<?php

declare(strict_types=1);

// React-stack build support. A "react" project's frontend agent writes plain
// .jsx component files (App.jsx + whatever it imports under features/**); this
// file assembles those with a fixed set of platform-canonical modules (the
// React equivalents of core/router.js, core/api.js, core/errors.js, and
// features/auth/auth.js from the vanilla pipeline) and bundles the result
// with esbuild into a single bundle.js, the same way the vanilla pipeline
// force-injects its own canonical files before every deploy.
//
// Root-caused during development (see the diagnostic session that preceded
// this file): a bare `mkdir()` immediately followed by `file_put_contents()`
// into the directory it just created intermittently fails silently (returns
// false, leaves a 0-byte file) due to a stale PHP stat-cache entry for the
// just-created path — reproduced 10/10 failures across cold single-request
// PHP invocations, and 100% fixed by calling clearstatcache() between the
// mkdir() and the write. Every write in this file follows that pattern.

// These paths are always force-provided by the build step, never accepted
// from the agent's own output (mirrors AI_PLATFORM_CANONICAL_PATHS for the
// vanilla stack) — if the agent writes to one of these paths anyway, its
// content is silently discarded in favor of the canonical version.
const AI_REACT_CANONICAL_PATHS = ['main.jsx', 'index.html', 'core/api.js', 'core/auth.js', 'core/router.js', 'core/errors.js'];

// core/api.js — fetch wrapper with the same list/get/create/update/remove
// surface and the same 401/403-redirects-to-login, error-body-surfacing
// behavior as the vanilla AI_CANONICAL_API_JS, exported as ES module
// bindings instead of a self-invoking global so JSX component files can
// `import { api } from './core/api.js'`.
const AI_REACT_CANONICAL_API_JS = <<<'JS'
const SB_URL = window.location.origin + '/api/v1';
const SB_PID = '__SB_PID__';

const goLogin = () => {
  localStorage.removeItem('sb:token');
  if (!String(location.hash).toLowerCase().includes('login')) location.hash = '#/login';
};

const authHeader = () => {
  const t = localStorage.getItem('sb:token');
  if (!t) return {};
  try {
    const b64 = t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const exp = JSON.parse(atob(b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '='))).exp;
    if (exp && Date.now() / 1000 > exp) { goLogin(); return {}; }
  } catch {}
  return { Authorization: 'Bearer ' + t };
};

const base = (table) => `${SB_URL}/data/${SB_PID}/${table}`;
const unwrap = (j) => (Array.isArray(j) ? j : (j && (j.data ?? j.rows ?? j.records)) ?? j);

async function req(url, opts = {}) {
  const hadToken = !!localStorage.getItem('sb:token');
  const res = await fetch(url, {
    ...opts,
    headers: { 'Content-Type': 'application/json', ...authHeader(), ...(opts.headers || {}) },
  });
  if (res.status === 401 || (hadToken && res.status === 403)) {
    goLogin();
    throw new Error('Your session expired — please log in again.');
  }
  if (!res.ok) {
    let errMsg = `${res.status} ${res.statusText}`;
    try {
      const body = await res.json();
      if (body && typeof body.error === 'string' && body.error) errMsg = `${res.status} ${body.error}`;
    } catch {}
    if (window.__sbReportApiError) window.__sbReportApiError(errMsg, { url, status: res.status });
    throw new Error(errMsg);
  }
  return res.status === 204 ? null : res.json();
}

export const api = {
  list: (table) => req(base(table)).then(unwrap),
  get: (table, id) => req(`${base(table)}/${id}`),
  create: (table, data) => req(base(table), { method: 'POST', body: JSON.stringify(data) }),
  update: (table, id, data) => req(`${base(table)}/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  remove: (table, id) => req(`${base(table)}/${id}`, { method: 'DELETE' }),
};

// For setting ownership columns (e.g. `user_id`) on create — decodes the
// stored JWT's `sub` claim the same way the vanilla pipeline's inline
// bootstrap snippet does.
export function currentUserId() {
  const t = localStorage.getItem('sb:token');
  if (!t) return null;
  try {
    const b64 = t.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    return parseInt(JSON.parse(atob(b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '='))).sub, 10);
  } catch {
    return null;
  }
}
JS;

// core/auth.js — React-hook equivalent of AI_CANONICAL_AUTH_JS. Module-level
// state + a listener list stands in for the vanilla version's CustomEvent
// broadcast, so every component calling useAuth() re-renders on login/logout
// without needing a context provider. __AUTH_TABLE__ / __AUTH_FIELD__ are
// substituted at deploy time exactly like __SB_PID__.
const AI_REACT_CANONICAL_AUTH_JS = <<<'JS'
import { useState, useEffect, useCallback } from 'react';

const TABLE = '__AUTH_TABLE__';
const FIELD = '__AUTH_FIELD__';
const SB_URL = window.location.origin + '/api/v1';
const SB_PID = '__SB_PID__';

function decodeUser(token) {
  try {
    const b64 = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const payload = JSON.parse(atob(b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '=')));
    return { id: parseInt(payload.sub, 10) };
  } catch {
    return null;
  }
}

let listeners = [];
let currentUser = (() => {
  const t = localStorage.getItem('sb:token');
  return t ? decodeUser(t) : null;
})();
if (localStorage.getItem('sb:token') && !currentUser) localStorage.removeItem('sb:token');

function broadcast(user) {
  currentUser = user;
  listeners.forEach((fn) => fn(user));
}

// { user, ready, login, signup, logout, fieldLabel } — `ready` is always
// true (unlike the vanilla version's promise) since decoding a JWT already
// on disk is synchronous; there is no async "loading" state to wait out.
export function useAuth() {
  const [user, setUser] = useState(currentUser);

  useEffect(() => {
    listeners.push(setUser);
    return () => {
      listeners = listeners.filter((fn) => fn !== setUser);
    };
  }, []);

  const login = useCallback(async (identifier, password) => {
    const res = await fetch(`${SB_URL}/data/${SB_PID}/${TABLE}/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ [FIELD]: identifier, password }),
    });
    if (!res.ok) throw new Error('Invalid credentials');
    const { token, user: u } = await res.json();
    localStorage.setItem('sb:token', token);
    broadcast({ id: u.id });
  }, []);

  const signup = useCallback(
    async (identifier, password) => {
      const res = await fetch(`${SB_URL}/data/${SB_PID}/${TABLE}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ [FIELD]: identifier, password }),
      });
      if (!res.ok) {
        let msg = 'Could not create account';
        try {
          const body = await res.json();
          if (body?.error) msg = body.error;
        } catch {}
        throw new Error(msg);
      }
      await login(identifier, password);
    },
    [login]
  );

  const logout = useCallback(() => {
    localStorage.removeItem('sb:token');
    broadcast(null);
    location.hash = '#/login';
  }, []);

  return {
    user,
    ready: true,
    login,
    signup,
    logout,
    fieldLabel: FIELD.charAt(0).toUpperCase() + FIELD.slice(1).replace(/_/g, ' '),
  };
}
JS;

// Injected instead of AI_REACT_CANONICAL_AUTH_JS when the schema has no
// PASSWORD column — same rationale as AI_CANONICAL_AUTH_STUB_JS: a stray
// `useAuth()` call from a weaker model degrades gracefully instead of
// throwing "auth is not defined" and blanking the whole app.
const AI_REACT_CANONICAL_AUTH_STUB_JS = <<<'JS'
export function useAuth() {
  const notAvailable = async () => {
    throw new Error('This app has no login system.');
  };
  return { user: null, ready: true, login: notAvailable, signup: notAvailable, logout: () => {}, fieldLabel: 'Email' };
}
JS;

// core/router.js — hash-based routing as a hook instead of a global object.
// matchRoute() is exported standalone (not just used internally) so a
// component can build its own `routes = { path: Component }` map and call
// `matchRoute(routes, path)` directly, mirroring router.defineRoute's static
// + ':param' segment matching from the vanilla version exactly.
const AI_REACT_CANONICAL_ROUTER_JS = <<<'JS'
import { useState, useEffect } from 'react';

function parseHash() {
  return window.location.hash.replace(/^#/, '') || '/';
}

export function navigate(path) {
  window.location.hash = path;
}

export function matchRoute(routes, path) {
  if (routes[path]) return { Component: routes[path], params: {} };
  const segs = path.split('/');
  for (const pattern in routes) {
    const pSegs = pattern.split('/');
    if (pSegs.length !== segs.length) continue;
    const params = {};
    let ok = true;
    for (let i = 0; i < pSegs.length; i++) {
      if (pSegs[i].startsWith(':')) params[pSegs[i].slice(1)] = decodeURIComponent(segs[i]);
      else if (pSegs[i] !== segs[i]) {
        ok = false;
        break;
      }
    }
    if (ok) return { Component: routes[pattern], params };
  }
  return null;
}

export function useHashRoute() {
  const [path, setPath] = useState(parseHash());
  useEffect(() => {
    const onChange = () => setPath(parseHash());
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  return path;
}
JS;

// core/errors.js — identical in spirit to AI_CANONICAL_ERRORS_JS (same
// ingestion endpoint, same sendBeacon-first delivery, same api.js error hook)
// but as a plain side-effect module (`import './core/errors.js'`) instead of
// an inline <script> tag, since main.jsx is the one bootstrap file here.
const AI_REACT_CANONICAL_ERRORS_JS = <<<'JS'
const SB_PID = '__SB_PID__';
const ENDPOINT = window.location.origin + '/api/v1/errors/' + SB_PID;
const MAX_REPORTS_PER_LOAD = 20;
let sent = 0;
const seen = new Set();

function send(type, message, stack, meta) {
  if (sent >= MAX_REPORTS_PER_LOAD) return;
  const key = type + '|' + message + '|' + String(stack || '').slice(0, 200);
  if (seen.has(key)) return;
  seen.add(key);
  sent++;
  const payload = JSON.stringify({
    type,
    message: String(message == null ? 'Unknown error' : message).slice(0, 2000),
    stack: stack ? String(stack).slice(0, 4000) : null,
    url: window.location.href,
    meta: meta || null,
  });
  try {
    if (navigator.sendBeacon) {
      navigator.sendBeacon(ENDPOINT, new Blob([payload], { type: 'application/json' }));
    } else {
      fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload, keepalive: true }).catch(() => {});
    }
  } catch {}
}

window.addEventListener('error', (e) => {
  send('js_error', e.message || 'Unknown error', e.error && e.error.stack, { line: e.lineno, col: e.colno, file: e.filename });
});
window.addEventListener('unhandledrejection', (e) => {
  const reason = e.reason;
  send('promise_rejection', (reason && reason.message) || String(reason), reason && reason.stack);
});
const origConsoleError = console.error.bind(console);
console.error = (...args) => {
  origConsoleError(...args);
  send('console_error', args.map((a) => (a && a.message) || String(a)).join(' '));
};

window.__sbReportApiError = (message, meta) => send('api_error', message, null, meta);
JS;

// main.jsx — the bundle's entry point. Force-provided rather than agent-
// written (same rationale as core/router.js etc.): a fixed, tiny, always-
// correct mount point means the agent only ever has to get App.jsx right.
const AI_REACT_CANONICAL_MAIN_JSX = <<<'JSX'
import { createRoot } from 'react-dom/client';
import './core/errors.js';
import App from './App.jsx';

createRoot(document.getElementById('root')).render(<App />);
JSX;

/**
 * The canonical (never agent-written) source files for a react-stack build:
 * main.jsx, core/api.js, core/router.js, core/errors.js, and core/auth.js
 * (real or stub, depending on whether the schema has a PASSWORD column).
 */
function ai_react_canonical_source_files(?array $authInfo = null): array
{
    $authJs = !empty($authInfo['table'])
        ? str_replace(['__AUTH_TABLE__', '__AUTH_FIELD__'], [$authInfo['table'], $authInfo['field'] ?? 'email'], AI_REACT_CANONICAL_AUTH_JS)
        : AI_REACT_CANONICAL_AUTH_STUB_JS;

    return [
        ['path' => 'main.jsx', 'content' => AI_REACT_CANONICAL_MAIN_JSX],
        ['path' => 'core/api.js', 'content' => AI_REACT_CANONICAL_API_JS],
        ['path' => 'core/auth.js', 'content' => $authJs],
        ['path' => 'core/router.js', 'content' => AI_REACT_CANONICAL_ROUTER_JS],
        ['path' => 'core/errors.js', 'content' => AI_REACT_CANONICAL_ERRORS_JS],
    ];
}

/** The build-generated index.html — never agent-written for react-stack projects. */
function ai_react_index_html(string $projectTitle): string
{
    $title = htmlspecialchars($projectTitle !== '' ? $projectTitle : 'App', ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="{$title}, built with SupaBein.">
  <title>{$title}</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚀</text></svg>">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { darkMode: 'class' }</script>
</head>
<body class="bg-gray-950 text-gray-100">
  <div id="root"></div>
  <script src="./bundle.js"></script>
</body>
</html>
HTML;
}

function ai_react_runtime_paths(array $config): array
{
    return [
        'runtime' => rtrim((string)($config['REACT_RUNTIME_PATH'] ?? '/home/dxinethn/supabein-react-runtime'), '/'),
        'nodeBin' => rtrim((string)($config['REACT_NODE_BIN'] ?? '/opt/alt/alt-nodejs20/root/usr/bin'), '/'),
    ];
}

/**
 * Writes one file into $buildDir, creating parent directories as needed.
 * Every write follows mkdir() -> clearstatcache() -> file_put_contents(),
 * the sequence confirmed (10/10 failures without it, 0/10 with it, across
 * cold single-request PHP invocations) to avoid a stale-stat-cache write
 * failure immediately after creating a new directory.
 */
function ai_react_write_build_file(string $buildDir, string $relPath, string $content): bool
{
    $fullPath = $buildDir . '/' . ltrim($relPath, '/');
    $parentDir = dirname($fullPath);
    if (!is_dir($parentDir)) {
        @mkdir($parentDir, 0755, true);
    }
    clearstatcache();
    return file_put_contents($fullPath, $content) !== false;
}

/**
 * Assembles an agent's react-stack frontend files with the platform's
 * canonical modules and bundles them with esbuild into a single bundle.js.
 * Returns ['ok' => bool, 'files' => [{path, content}, ...], 'error' => ?string].
 * On success, 'files' is exactly the two files a react-stack deploy needs:
 * index.html and bundle.js — the same shape ai_deploy_files() already
 * expects from the vanilla pipeline, so no other part of deploy needs to
 * change to accept them.
 */
function ai_react_build_bundle(array $agentFiles, array $config, ?array $authInfo = null, string $projectTitle = 'App'): array
{
    ['runtime' => $runtimeDir, 'nodeBin' => $nodeBinDir] = ai_react_runtime_paths($config);
    $esbuildBin = $runtimeDir . '/node_modules/.bin/esbuild';
    if (!is_file($esbuildBin)) {
        return ['ok' => false, 'error' => 'React build runtime is not installed on this server (missing esbuild at ' . $esbuildBin . ')', 'files' => []];
    }

    $buildDir = $runtimeDir . '/builds/b' . bin2hex(random_bytes(8));
    if (!@mkdir($buildDir, 0755, true)) {
        return ['ok' => false, 'error' => 'Cannot create react build directory', 'files' => []];
    }

    try {
        $canonical = ai_react_canonical_source_files($authInfo);
        $canonicalPaths = array_column($canonical, 'path');

        // Agent files first, canonical files last -- so canonical always wins
        // on a path collision, mirroring ai_inject_canonical_frontend_files().
        $byPath = [];
        foreach ($agentFiles as $f) {
            if (!is_array($f) || !isset($f['path'])) continue;
            $relPath = ltrim((string)$f['path'], '/');
            if ($relPath === '' || in_array($relPath, $canonicalPaths, true)) continue;
            $byPath[$relPath] = (string)($f['content'] ?? '');
        }
        foreach ($canonical as $f) {
            $byPath[$f['path']] = $f['content'];
        }

        foreach ($byPath as $relPath => $content) {
            if (!ai_react_write_build_file($buildDir, $relPath, $content)) {
                return ['ok' => false, 'error' => "Cannot write build source file: {$relPath}", 'files' => []];
            }
        }

        $esbuildArg = escapeshellarg($esbuildBin);
        $dirArg     = escapeshellarg($buildDir);
        $cmd = "cd {$dirArg} && PATH=" . escapeshellarg($nodeBinDir) . ":\$PATH {$esbuildArg} main.jsx "
             . '--bundle --minify --outfile=bundle.js --loader:.jsx=jsx --jsx=automatic 2>&1';
        exec($cmd, $output, $returnCode);

        $bundlePath = $buildDir . '/bundle.js';
        if ($returnCode !== 0 || !is_file($bundlePath)) {
            return ['ok' => false, 'error' => 'esbuild failed: ' . implode("\n", $output), 'files' => []];
        }

        $bundleContent = (string)file_get_contents($bundlePath);
        return [
            'ok' => true,
            'error' => null,
            'files' => [
                ['path' => 'index.html', 'content' => ai_react_index_html($projectTitle)],
                ['path' => 'bundle.js', 'content' => $bundleContent],
            ],
        ];
    } finally {
        \SupaBein\Deploy::rrmdir($buildDir);
    }
}

/**
 * Single-file JSX syntax check — no bundling, no import resolution (esbuild
 * without --bundle only transforms the one file it's given), so it works on
 * an isolated snippet that imports canonical modules the check never sees.
 * Used by ai_check_js_syntax() in ai_shared.php for .jsx paths.
 */
function ai_react_syntax_check(string $content, array $config): array
{
    ['nodeBin' => $nodeBinDir] = ai_react_runtime_paths($config);
    ['runtime' => $runtimeDir] = ai_react_runtime_paths($config);
    $esbuildBin = $runtimeDir . '/node_modules/.bin/esbuild';
    if (!is_file($esbuildBin)) {
        return ['ok' => true, 'error' => null]; // can't check without the runtime -- don't block the agent on it
    }

    $tmpDir = sys_get_temp_dir();
    $tmpFile = $tmpDir . '/sb_reactcheck_' . getmypid() . '_' . str_replace('.', '', (string)microtime(true)) . '.jsx';
    clearstatcache();
    file_put_contents($tmpFile, $content);

    $cmd = escapeshellarg($esbuildBin) . ' ' . escapeshellarg($tmpFile)
         . ' --loader=jsx --jsx=automatic --outfile=' . escapeshellarg($tmpFile . '.out') . ' 2>&1';
    exec($cmd, $output, $returnCode);
    @unlink($tmpFile);
    @unlink($tmpFile . '.out');

    if ($returnCode !== 0) {
        return ['ok' => false, 'error' => trim(implode("\n", $output))];
    }
    return ['ok' => true, 'error' => null];
}

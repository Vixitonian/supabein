'use strict';

// Shown only when NOTHING got seeded at all — no global tables to fill, and
// no auth table to create test login accounts against either (e.g. a schema
// with a single user-owned table and no way to log in and see it).
const NO_SEED_ELIGIBLE_MSG = "No eligible tables to seed, and this app has no auth table to create test " +
  'login accounts against either — there\'s nothing "global" to fill and no way to log in and see ' +
  'user-owned data. This is expected for some schemas, not a failure.';

// Builds the seed result message shared by the AI panel's seed flow and the
// Overview page's Seed App button — test accounts (if any were created) are
// surfaced prominently since they're the only way to actually see rows
// seeded into user-owned tables (see ai_seed_test_accounts on the backend).
function formatSeedResultMessage(result) {
  const seeded   = result?.seeded || [];
  const accounts = result?.test_accounts || [];
  if (!seeded.length && !accounts.length) return NO_SEED_ELIGIBLE_MSG;

  const parts = [];
  if (seeded.length) parts.push('Seeded: ' + seeded.join(', ') + '.');
  if (accounts.length) {
    const logins = accounts.map(a => `${a.identifier} / ${a.password}`).join(', ');
    parts.push(`Test login${accounts.length !== 1 ? 's' : ''}: ${logins} — log in to see the seeded data.`);
  }
  return parts.join(' ');
}

// ─── Auth ────────────────────────────────────────────────────────────────────

const Auth = (() => {
  const KEY = 'sb_token';

  function getToken() { return localStorage.getItem(KEY); }
  function setToken(t) { localStorage.setItem(KEY, t); }
  function clear() { localStorage.removeItem(KEY); }
  function isLoggedIn() { return !!getToken(); }

  function parseJwt(token) {
    try {
      return JSON.parse(atob(token.split('.')[1]));
    } catch { return null; }
  }

  function getUser() {
    const t = getToken();
    return t ? parseJwt(t) : null;
  }

  return { getToken, setToken, clear, isLoggedIn, getUser };
})();

// ─── Global loading bar ──────────────────────────────────────────────────────
// A slim top-of-viewport bar that appears whenever a network request is in
// flight, so a slow connection reads as "working" instead of "hung" — covers
// every page navigation, the AI panel opening, and session loads uniformly
// without needing a bespoke spinner in each render function.
const LoadingBar = (() => {
  let count = 0;
  let el = null;
  let hideTimer = null;

  function ensure() {
    if (el) return el;
    el = document.createElement('div');
    el.id = 'sb-loading-bar';
    document.body.appendChild(el);
    return el;
  }

  function start() {
    count++;
    if (count === 1) {
      clearTimeout(hideTimer);
      const bar = ensure();
      bar.classList.remove('done');
      // Force reflow so re-adding 'active' restarts the width transition
      // even if the bar never fully faded out from a previous request.
      void bar.offsetWidth;
      bar.classList.add('active');
    }
  }

  function end() {
    count = Math.max(0, count - 1);
    if (count === 0 && el) {
      el.classList.add('done');
      hideTimer = setTimeout(() => { el.classList.remove('active', 'done'); }, 300);
    }
  }

  return { start, end };
})();

// ─── API ─────────────────────────────────────────────────────────────────────

const Api = (() => {
  // Derive API base from current location
  const BASE = (() => {
    const parts = window.location.pathname.split('/');
    // Remove 'dashboard/index.html' or similar to get to /api
    const base = window.location.origin + '/api';
    return base;
  })();

  async function request(method, path, data, isFormData = false, abortSignal = null) {
    const headers = {};
    const token = Auth.getToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;

    let body;
    if (isFormData) {
      body = data; // FormData — no Content-Type header (browser sets it with boundary)
    } else if (data && method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(data);
    }

    // Job-status polling fires every ~2s while a job runs and already has its
    // own progress card — showing the global bar for it too would just flicker.
    const showBar = !path.includes('/ai/jobs/');
    if (showBar) LoadingBar.start();

    const signal = abortSignal ?? AbortSignal.timeout(600000);
    let res;
    try {
      res = await fetch(BASE + path, { method, headers, body, signal });
    } catch (e) {
      if (showBar) LoadingBar.end();
      if (e instanceof DOMException) {
        if (abortSignal?.aborted) throw e;
        if (e.name === 'TimeoutError' || e.name === 'AbortError') {
          throw new ApiError('Request timed out after 10 minutes — the server took too long. Try a simpler prompt or check server health.', 408, {});
        }
      }
      throw e;
    }
    if (showBar) LoadingBar.end();

    if (res.status === 401) {
      Auth.clear();
      Router.navigate('/login');
      throw new ApiError('Session expired — please log in again', 401, {});
    }

    // Sliding renewal: backend sends a fresh token on every authenticated call
    const refreshed = res.headers.get('X-Refresh-Token');
    if (refreshed) Auth.setToken(refreshed);

    const json = await res.json().catch(() => ({}));

    if (!res.ok) {
      const err = new ApiError(json.error || 'Request failed', res.status, json);
      console.error('[SupaBein] API error', { method, path, status: res.status, message: err.message });
      throw err;
    }

    return json;
  }

  class ApiError extends Error {
    constructor(msg, status, data = {}) {
      super(msg);
      this.status = status;
      this.data = data;
    }
  }

  return {
    BASE,
    get:    (path)       => request('GET',    path),
    post:   (path, data, signal) => request('POST',   path, data, false, signal),
    patch:  (path, data) => request('PATCH',  path, data),
    put:    (path, data) => request('PUT',    path, data),
    delete: (path)       => request('DELETE', path),
    upload: (path, formData) => request('POST', path, formData, true),
    ApiError,
  };
})();

// ─── Helpers ─────────────────────────────────────────────────────────────────

function el(tag, attrs = {}, ...children) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k.startsWith('on')) e.addEventListener(k.slice(2).toLowerCase(), v);
    else if (k === 'class') e.className = v;
    else e.setAttribute(k, v);
  }
  for (const c of children.flat()) {
    if (c == null || c === false) continue;
    if (c instanceof Node) e.appendChild(c);
    else e.appendChild(document.createTextNode(typeof c === 'string' ? c : String(c)));
  }
  return e;
}

function h(html) {
  const d = document.createElement('div');
  d.innerHTML = html.trim();
  return d;
}

function setApp(node) {
  const app = document.getElementById('app');
  app.innerHTML = '';
  if (typeof node === 'string') {
    app.innerHTML = node;
  } else {
    app.appendChild(node instanceof HTMLElement ? node : node.firstChild);
  }
}

function showAlert(container, msg, type = 'error') {
  const a = container.querySelector('.alert');
  if (a) a.remove();
  const div = el('div', { class: `alert alert-${type}` }, msg);
  container.insertBefore(div, container.firstChild);
}

// Backend timestamps are plain "YYYY-MM-DD HH:MM:SS" with no timezone marker,
// and are always UTC (the DB connection is pinned to UTC in bootstrap.php).
// A bare string like that gets parsed as LOCAL time by `new Date()`, which
// silently shifts every timestamp by the viewer's UTC offset — this is what
// made brand-new activity read as hours old. Force UTC interpretation instead.
function parseServerDate(ts) {
  if (!ts) return null;
  let iso = String(ts).replace(' ', 'T');
  if (!/[zZ]|[+-]\d\d:?\d\d$/.test(iso)) iso += 'Z';
  const d = new Date(iso);
  return isNaN(d) ? null : d;
}

function fmtDate(d) {
  const parsed = parseServerDate(d);
  return parsed ? parsed.toLocaleString() : '—';
}


function downloadText(filename, content) {
  const blob = new Blob([content], { type: 'text/plain' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}

function makeCollapsible(card, open = false) {
  const title = card.querySelector('.api-table-title');
  if (!title) return;
  const bodyChildren = Array.from(card.children).filter(c => c !== title);
  const body = document.createElement('div');
  body.className = 'collaps-body';
  body.style.display = open ? '' : 'none';
  bodyChildren.forEach(c => body.appendChild(c));
  card.appendChild(body);
  const chevron = el('span', { class: 'collaps-chevron' }, open ? '▾' : '▸');
  title.classList.add('collaps-title');
  title.appendChild(chevron);
  title.addEventListener('click', () => {
    const nowOpen = body.style.display !== 'none';
    body.style.display = nowOpen ? 'none' : '';
    chevron.textContent = nowOpen ? '▸' : '▾';
  });
}

function requireAuth() {
  if (!Auth.isLoggedIn()) {
    Router.navigate('/login');
    return false;
  }
  return true;
}

// ─── Layout ──────────────────────────────────────────────────────────────────

function renderLayout(projectId, activeTab, content, opts = {}) {
  const user = Auth.getUser();
  const initials = user?.email ? user.email[0].toUpperCase() : '?';

  let projectName = null;
  if (projectId) {
    projectName = opts.projectName
      || sessionStorage.getItem('sb_proj_' + projectId)
      || ('Project #' + projectId);
    if (opts.projectName) {
      sessionStorage.setItem('sb_proj_' + projectId, opts.projectName);
    }
  }

  const isOn = (tab) => activeTab === tab ? ' active' : '';

  const projectNavItems = projectId ? [
    el('div', { class: 'sb-divider' }),
    el('div', { class: 'sb-nav' },
      el('div', { class: 'sb-project-ctx' },
        el('span', { class: 'sb-project-dot' }),
        el('span', { class: 'sb-project-name' }, projectName)
      ),
      el('a', { href: `#/projects/${projectId}/tables`, class: 'sb-link' + isOn('tables') },
        el('span', { class: 'sb-icon' }, '▦'),
        'Tables'
      ),
      el('a', { href: `#/projects/${projectId}/sites`, class: 'sb-link' + isOn('sites') },
        el('span', { class: 'sb-icon' }, '⇧'),
        'Deploy'
      ),
      el('a', { href: `#/projects/${projectId}/api`, class: 'sb-link' + isOn('api') },
        el('span', { class: 'sb-icon' }, '⟨⟩'),
        'API'
      ),

    )
  ] : [];

  const sidebar = el('nav', { class: 'sidebar' },
    el('div', { class: 'sb-brand' },
      el('span', { class: 'sb-logo-mark' }, 'SB'),
      el('span', { class: 'sb-logo-text' }, 'SupaBein')
    ),
    el('div', { class: 'sb-nav' },
      el('div', { class: 'sb-section-label' }, 'Workspace'),
      el('a', { href: '#/home', class: 'sb-link' + (activeTab === 'home' ? ' active' : '') },
        el('span', { class: 'sb-icon' }, '⌂'),
        'Home'
      ),
      el('a', { href: '#/projects', class: 'sb-link' + (!projectId && activeTab !== 'account' && activeTab !== 'home' ? ' active' : '') },
        el('span', { class: 'sb-icon' }, '⊟'),
        'Projects'
      )
    ),
    ...projectNavItems,
    el('div', { class: 'sb-spacer' }),
    el('div', { class: 'sb-divider' }),
    el('div', { class: 'sb-bottom' },
      el('a', { href: '#/account', class: 'sb-link sb-user-link' + isOn('account') },
        el('span', { class: 'sb-avatar' }, initials),
        el('div', { class: 'sb-user-details' },
          el('span', { class: 'sb-user-name' }, user?.email || 'Account'),
          el('span', { class: 'sb-user-sub' }, 'Account & Settings')
        )
      ),
      el('a', { href: '#/logout', class: 'sb-link sb-logout' },
        el('span', { class: 'sb-icon' }, '→'),
        'Sign Out'
      )
    )
  );

  // Mobile: hamburger button that toggles the drawer
  const burger = el('button', { class: 'sb-hamburger', 'aria-label': 'Toggle menu' },
    el('span'), el('span'), el('span')
  );
  // No global AI entry point: Home's "Build with AI" starts new projects, and
  // each project's Overview tab has its own "Edit with AI" scoped to it — the
  // AI panel is always tied to a specific project (or none, for a new build),
  // never switchable mid-conversation.
  const topbarRight = el('div', { class: 'sb-topbar-right' });
  const topbar = el('div', { class: 'sb-topbar' },
    burger,
    el('div', { class: 'sb-topbar-brand' },
      el('span', { class: 'sb-logo-mark' }, 'SB'),
      el('span', { class: 'sb-logo-text' }, 'SupaBein')
    ),
    topbarRight
  );

  // Dark overlay behind the open drawer
  const overlay = el('div', { class: 'sb-overlay' });

  function openDrawer() {
    sidebar.classList.add('open');
    overlay.classList.add('open');
    burger.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeDrawer() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    burger.classList.remove('open');
    document.body.style.overflow = '';
  }

  burger.addEventListener('click', () =>
    sidebar.classList.contains('open') ? closeDrawer() : openDrawer()
  );
  overlay.addEventListener('click', closeDrawer);

  // Any nav link tap closes the drawer (navigation happened)
  sidebar.querySelectorAll('.sb-link').forEach(a => a.addEventListener('click', closeDrawer));

  const wrap = el('div', { class: 'layout' }, sidebar, el('main', { class: 'main', id: 'content' }, ...content));
  const app = document.getElementById('app');
  app.innerHTML = '';
  app.appendChild(topbar);
  app.appendChild(overlay);
  app.appendChild(wrap);
}

// ─── Pages ───────────────────────────────────────────────────────────────────

// Login
function renderLanding() {
  const wrap = el('div', { class: 'landing-wrap' },
    el('div', { class: 'landing-hero' },
      authBrand(),
      el('h1', { class: 'landing-headline' }, 'Your idea. Now a real app.'),
      el('p', { class: 'landing-sub' },
        'SupaBein turns a plain-English description into a working database, API, and live ' +
        'web app — no developers, no code, no waiting. Built for founders, business owners, ' +
        'and professionals who need to move from idea to reality today.'
      ),
      el('div', { class: 'landing-ctas' },
        el('a', { class: 'btn btn-primary', href: '#/signup' }, 'Get Started Free'),
        el('a', { class: 'btn btn-secondary', href: '#/login' }, 'Sign In')
      )
    ),
    el('div', { class: 'landing-features' },
      el('div', { class: 'landing-feature card' },
        el('div', { class: 'landing-feature-title' }, 'Describe it, get it built'),
        el('div', { class: 'landing-feature-text' }, 'Tell it what you need in plain English — a booking system, a store, a directory — and get a real, database-backed app in minutes.')
      ),
      el('div', { class: 'landing-feature card' },
        el('div', { class: 'landing-feature-title' }, 'Own everything'),
        el('div', { class: 'landing-feature-text' }, 'Self-hosted, on your infrastructure. Your data and your app stay yours — no vendor lock-in, no surprise bills.')
      ),
      el('div', { class: 'landing-feature card' },
        el('div', { class: 'landing-feature-title' }, 'Launch today'),
        el('div', { class: 'landing-feature-text' }, 'Go from a written idea to a live, working site the same day — then keep shaping it with plain-English edits.')
      )
    )
  );
  setApp(wrap);
}

function authBrand() {
  const homeHref = Auth.isLoggedIn() ? '#/projects' : '/';
  return h(`<a class="auth-brand" href="${homeHref}">
    <div class="auth-mark">SB</div>
    <span class="auth-brand-name">SupaBein</span>
  </a>`);
}

function renderLogin() {
  const wrap = el('div', { class: 'auth-wrap' },
    el('div', { class: 'auth-box card' },
      authBrand(),
      el('div', { class: 'auth-sub' }, 'Sign in to your account'),
      el('div', { class: 'form-group' },
        el('label', {}, 'Email'),
        el('input', { type: 'email', id: 'email', placeholder: 'you@example.com', autocomplete: 'email' })
      ),
      el('div', { class: 'form-group' },
        el('label', {}, 'Password'),
        el('input', { type: 'password', id: 'password', autocomplete: 'current-password' }),
        el('a', { class: 'auth-forgot', href: '#/forgot' }, 'Forgot password?')
      ),
      el('button', { class: 'btn btn-primary w-full', id: 'submit' }, 'Sign In'),
      el('div', { class: 'auth-switch' },
        'No account? ', el('a', { href: '#/signup' }, 'Sign up')
      )
    )
  );

  wrap.querySelector('#submit').addEventListener('click', async () => {
    const email    = wrap.querySelector('#email').value;
    const password = wrap.querySelector('#password').value;
    const btn = wrap.querySelector('#submit');
    btn.disabled = true; btn.textContent = 'Signing in…';
    try {
      const res = await Api.post('/v1/auth/login', { email, password });
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      showAlert(wrap.querySelector('.auth-box'), e.message);
      btn.disabled = false; btn.textContent = 'Sign In';
    }
  });

  wrap.querySelector('#password').addEventListener('keydown', e => {
    if (e.key === 'Enter') wrap.querySelector('#submit').click();
  });

  setApp(wrap);
}

function renderSignup() {
  const wrap = el('div', { class: 'auth-wrap' },
    el('div', { class: 'auth-box card' },
      authBrand(),
      el('div', { class: 'auth-sub' }, 'Create your SupaBein account'),
      el('div', { class: 'form-group' },
        el('label', {}, 'Email'),
        el('input', { type: 'email', id: 'email', placeholder: 'you@example.com', autocomplete: 'email' })
      ),
      el('div', { class: 'form-group' },
        el('label', {}, 'Password'),
        el('input', { type: 'password', id: 'password', placeholder: 'At least 8 characters', autocomplete: 'new-password' })
      ),
      el('button', { class: 'btn btn-primary w-full', id: 'submit' }, 'Create Account'),
      el('div', { class: 'auth-switch' },
        'Already have an account? ', el('a', { href: '#/login' }, 'Sign in')
      )
    )
  );

  wrap.querySelector('#submit').addEventListener('click', async () => {
    const email    = wrap.querySelector('#email').value;
    const password = wrap.querySelector('#password').value;
    const btn = wrap.querySelector('#submit');
    btn.disabled = true; btn.textContent = 'Creating account…';
    try {
      const res = await Api.post('/v1/auth/signup', { email, password });
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      showAlert(wrap.querySelector('.auth-box'), e.message);
      btn.disabled = false; btn.textContent = 'Create Account';
    }
  });

  wrap.querySelector('#password').addEventListener('keydown', e => {
    if (e.key === 'Enter') wrap.querySelector('#submit').click();
  });

  setApp(wrap);
}

function renderForgot() {
  const wrap = el('div', { class: 'auth-wrap' },
    el('div', { class: 'auth-box card' },
      authBrand(),
      el('div', { class: 'auth-sub' }, 'Reset your password'),
      el('p', { style: 'font-size:13px;color:var(--text-muted);margin-bottom:20px;text-align:center' },
        'Enter your email and we\'ll send you a password reset link.'
      ),
      el('div', { class: 'form-group' },
        el('label', {}, 'Email'),
        el('input', { type: 'email', id: 'email', placeholder: 'you@example.com', autocomplete: 'email' })
      ),
      el('button', { class: 'btn btn-primary w-full', id: 'submit' }, 'Send Reset Link'),
      el('div', { class: 'auth-switch' }, el('a', { href: '#/login' }, '← Back to sign in'))
    )
  );

  wrap.querySelector('#submit').addEventListener('click', async () => {
    const email = wrap.querySelector('#email').value;
    const btn = wrap.querySelector('#submit');
    btn.disabled = true; btn.textContent = 'Sending…';
    try {
      const res = await Api.post('/v1/auth/forgot', { email });
      const box = wrap.querySelector('.auth-box');
      box.innerHTML = '';
      box.appendChild(authBrand());
      box.appendChild(h(`<div class="auth-sub">Check your email</div>`));
      box.appendChild(h(`<p style="font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:16px">${res.message}</p>`));
      box.appendChild(el('a', { class: 'btn btn-secondary w-full', href: '#/login' }, '← Back to sign in'));
    } catch (e) {
      showAlert(wrap.querySelector('.auth-box'), e.message);
      btn.disabled = false; btn.textContent = 'Send Reset Link';
    }
  });

  setApp(wrap);
}

function renderReset(params) {
  const tokenFromLink = (params && params.token) ? decodeURIComponent(params.token) : '';
  const wrap = el('div', { class: 'auth-wrap' },
    el('div', { class: 'auth-box card' },
      authBrand(),
      el('div', { class: 'auth-sub' }, 'Set a new password'),
      el('div', { class: 'form-group', style: tokenFromLink ? 'display:none' : '' },
        el('label', {}, 'Reset Token'),
        el('input', { type: 'text', id: 'token', placeholder: 'Paste your reset token', value: tokenFromLink })
      ),
      el('div', { class: 'form-group' },
        el('label', {}, 'New Password'),
        el('input', { type: 'password', id: 'password', placeholder: 'At least 8 characters', autocomplete: 'new-password' })
      ),
      el('button', { class: 'btn btn-primary w-full', id: 'submit' }, 'Reset Password'),
      el('div', { class: 'auth-switch' }, el('a', { href: '#/login' }, '← Back to sign in'))
    )
  );

  wrap.querySelector('#submit').addEventListener('click', async () => {
    const token    = tokenFromLink || wrap.querySelector('#token').value.trim();
    const password = wrap.querySelector('#password').value;
    const btn = wrap.querySelector('#submit');
    btn.disabled = true; btn.textContent = 'Resetting…';
    try {
      const res = await Api.post('/v1/auth/reset', { token, password });
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      showAlert(wrap.querySelector('.auth-box'), e.message);
      btn.disabled = false; btn.textContent = 'Reset Password';
    }
  });

  setApp(wrap);
}

// ─── AI Panel ────────────────────────────────────────────────────────────────

const AiPanel = (() => {
  let isOpen = false;
  let historyPushed = false;
  let sessions = [];
  let currentSessionId = null;
  let projects = [];
  let selectedProjectId = null;
  let panelEl = null;
  let backdropEl = null;
  let sidebarVisible = false;
  let reviewEnabled = localStorage.getItem('sb:ai_review') === '1';
  let buildMode = localStorage.getItem('sb:ai_build') === '1';
  let liveTraceMsg = null;
  let operationInProgress = false;
  let operationMode = null;
  let currentAbortController = null;
  let activeJobPoll = null; // { cancelled: bool } while a build/edit job is being polled
  let sendBtnEl = null;

  // Ordered best-to-least capable for software/frontend generation (scale, tier, coding
  // pedigree, context window, and OpenRouter pricing as a capability proxy where relevant).
  const AI_MODELS = [
    { label: 'Claude Opus 4.8',      provider: 'anthropic',  model: 'claude-opus-4-8',                                   badge: 'Claude' },
    { label: 'Claude Sonnet 5',      provider: 'anthropic',  model: 'claude-sonnet-5',                                   badge: 'Claude' },
    { label: 'Nemotron 3 Ultra 550B',provider: 'nvidia',     model: 'nvidia/nemotron-3-ultra-550b-a55b',                 badge: 'NVIDIA' },
    { label: 'Kimi K2',              provider: 'openrouter', model: 'moonshotai/kimi-k2',                                badge: 'OpenRouter' },
    { label: 'GLM 5.2',              provider: 'nvidia',     model: 'z-ai/glm-5.2',                                      badge: 'NVIDIA' },
    { label: 'DeepSeek V4 Pro',      provider: 'nvidia',     model: 'deepseek-ai/deepseek-v4-pro',                       badge: 'NVIDIA' },
    { label: 'Qwen 3.5 122B',        provider: 'nvidia',     model: 'qwen/qwen3.5-122b-a10b',                            badge: 'NVIDIA' },
    { label: 'Nemotron Super 120B',  provider: 'openrouter', model: 'nvidia/nemotron-3-super-120b-a12b:free',            badge: 'Free' },
    { label: 'GPT OSS 120B',         provider: 'openrouter', model: 'openai/gpt-oss-120b:free',                          badge: 'Free' },
    { label: 'DeepSeek V4 Flash',    provider: 'nvidia',     model: 'deepseek-ai/deepseek-v4-flash',                     badge: 'NVIDIA' },
    { label: 'Gemini 2.5 Flash',     provider: 'gemini',     model: 'gemini-2.5-flash',                                  badge: 'Fast' },
    { label: 'Laguna M.1',           provider: 'openrouter', model: 'poolside/laguna-m.1:free',                          badge: 'Free' },
    { label: 'North Mini Code',      provider: 'openrouter', model: 'cohere/north-mini-code:free',                       badge: 'Free' },
    { label: 'Mistral Small 3.2',    provider: 'openrouter', model: 'mistralai/mistral-small-3.2-24b-instruct',          badge: 'OpenRouter' },
    { label: 'Nex N2 Pro',           provider: 'openrouter', model: 'nex-agi/nex-n2-pro',                                badge: 'OpenRouter' },
    { label: 'Gemma 4 26B (MoE)',    provider: 'openrouter', model: 'google/gemma-4-26b-a4b-it:free',                    badge: 'Free' },
    { label: 'Nemotron Nano Omni',   provider: 'openrouter', model: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free', badge: 'Free' },
    { label: 'GPT OSS 20B',          provider: 'openrouter', model: 'openai/gpt-oss-20b:free',                           badge: 'Free' },
    { label: 'Laguna XS.2',          provider: 'openrouter', model: 'poolside/laguna-xs.2:free',                         badge: 'Free' },
  ];

  function getSelectedModel() {
    try { return JSON.parse(localStorage.getItem('sb:ai_model')) || AI_MODELS[0]; }
    catch { return AI_MODELS[0]; }
  }
  function setSelectedModel(m) { localStorage.setItem('sb:ai_model', JSON.stringify(m)); }

  function updateModelBtn(m) {
    if (!panelEl) return;
    const btn = panelEl.querySelector('.ai-model-btn');
    if (btn) btn.textContent = m.label + ' ▾';
  }

  function showToast(message, duration = 4500) {
    if (!panelEl) return;
    const toast = el('div', { class: 'ai-toast' }, message);
    panelEl.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('ai-toast-visible'));
    setTimeout(() => {
      toast.classList.remove('ai-toast-visible');
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }

  const THINKING_STAGES = {
    build:    ['Analyzing your idea…', 'Designing data schema…', 'Writing frontend code…', 'Generating pages and API calls…', 'Polishing the output…', 'Finalizing your app…'],
    edit:     ['Reading current schema…', 'Planning the changes…', 'Generating edits…', 'Almost done…'],
    diagnose: ['Analyzing the issue…', 'Checking schema & policies…', 'Preparing suggestions…', 'Almost done…'],
    chat:     ['Thinking…', 'Looking up your projects…', 'Formulating reply…'],
    intent:   ['Analyzing your idea…', 'Identifying actors…', 'Distilling user stories…'],
    test:     ['Launching browser…', 'Running user-story tests…', 'Capturing results…', 'Still testing — this can take a minute…'],
    default:  ['Working on it…', 'Still going…', 'Almost done…'],
  };

  let stopThinkingStages = null;

  function startThinkingStages(labelEl, mode) {
    stopThinkingStages?.();
    const stages = THINKING_STAGES[mode] || THINKING_STAGES.default;
    let i = 0;
    labelEl.textContent = stages[0];
    const timer = setInterval(() => {
      i++;
      if (i < stages.length) {
        labelEl.textContent = stages[i];
      } else {
        labelEl.textContent = 'Still working — this can take a few minutes…';
      }
    }, 6000);
    stopThinkingStages = () => { clearInterval(timer); stopThinkingStages = null; };
  }

  function aiFriendlyError(e) {
    // Native connection/network error — no HTTP status code at all
    if (!e.status) {
      const msg = (e.message || '').toLowerCase();
      if (msg.includes('timeout') || msg.includes('abort') || e.name === 'TimeoutError' || e.name === 'AbortError') {
        return 'The request timed out after too long — the server may be under load. Your progress is saved; click Continue to retry.';
      }
      return 'Connection to the server was lost — check your internet and click Continue to retry.';
    }
    const code  = e.data?.code;
    const stage = e.data?.stage;
    const stageLabel = {
      schema:        'schema generation',
      schema_retry:  'schema generation (retry)',
      frontend:      'frontend generation',
      design_brief:  'design brief',
      edit:          'edit analysis',
      edit_retry:    'edit analysis (retry)',
      diagnose:      'diagnostics',
      chat:          'chat',
      suggest:       'suggestion analysis',
      intent:        'intent analysis',
      recover:       'error recovery',
    }[stage] || (stage ? stage.replace(/_/g, ' ') : 'AI call');
    const codeMsg = {
      rate_limit:     'The AI hit a rate limit — wait a moment and try again',
      content_filter: 'The AI filtered this request — try rephrasing your prompt',
      invalid_json:   'The AI returned an unexpected format — retrying may help',
      timeout:        'The AI timed out — try a shorter or simpler prompt',
      api_key:        'AI API key is invalid or expired',
      network:        'Can\'t reach the AI provider — check server connectivity',
      provider_error: 'The AI provider had an internal error — try again shortly',
    }[code];
    if (codeMsg) return `${codeMsg} (during ${stageLabel})`;
    return `AI error during ${stageLabel}: ${e.message}`;
  }

  async function callWithFallback(path, body, signal = null) {
    let selectedM = getSelectedModel();
    const tried = new Set();
    while (true) {
      tried.add(selectedM.model);
      try {
        return await Api.post(path, { ...body, provider: selectedM.provider, model: selectedM.model }, signal);
      } catch (e) {
        if (e.status === 402) {
          const nextModel = AI_MODELS.find(m => !tried.has(m.model));
          if (nextModel) {
            showToast(`${selectedM.label} hit its limit — switching to ${nextModel.label}`);
            setSelectedModel(nextModel);
            updateModelBtn(nextModel);
            selectedM = nextModel;
            continue;
          }
        }
        throw e;
      }
    }
  }

  function abortCurrentOperation() {
    if (currentAbortController) {
      currentAbortController.abort();
      currentAbortController = null;
    }
    const sess = currentSession();
    if (activeJobPoll) {
      activeJobPoll.cancelled = true;
      activeJobPoll = null;
      // Tell the server to actually kill the worker process too — matches the
      // old behavior where aborting the stream fetch also killed the PHP side.
      const jobMsg = sess && sess.messages.find(m => m.type === 'progress' && m.data.jobId && !m.data.jobDone);
      if (jobMsg) Api.post(`/v1/ai/jobs/${jobMsg.data.jobId}/cancel`, {}).catch(() => {});
    }
    // Reset the UI immediately so the stop button responds even if the
    // in-flight request takes a moment to unwind (or the backend keeps going).
    stopThinkingStages?.();
    operationInProgress = false;
    operationMode = null;
    if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; }
    if (sess) sess.messages = sess.messages.filter(m => m.type !== 'thinking');
    updateSendBtn();
    renderMessages();
  }

  function updateSendBtn() {
    if (!sendBtnEl) return;
    if (operationInProgress) {
      sendBtnEl.textContent = '■';
      sendBtnEl.disabled = false;
      sendBtnEl.classList.add('ai-send-btn--stop');
    } else {
      sendBtnEl.textContent = '↑';
      const ta = panelEl?.querySelector('#ai-textarea');
      sendBtnEl.disabled = !ta || ta.value.trim() === '';
      sendBtnEl.classList.remove('ai-send-btn--stop');
    }
  }

  function renderTokenUsage(usage) {
    if (!usage || !usage.total_tokens) return null;
    return el('div', { class: 'ai-token-usage' },
      `↑ ${usage.prompt_tokens.toLocaleString()} / ↓ ${usage.completion_tokens.toLocaleString()} tokens`
    );
  }

  function getOrCreateBackdrop() {
    if (!backdropEl) {
      backdropEl = document.createElement('div');
      backdropEl.className = 'ai-panel-backdrop';
      backdropEl.addEventListener('click', close);
      document.body.appendChild(backdropEl);
    }
    return backdropEl;
  }

  // Compare as strings — currentSessionId can come back from localStorage as a
  // string while session ids from the API are numbers, which would miss with ===.
  function getSession(id) { return sessions.find(s => String(s.id) === String(id)) || null; }
  function currentSession() { return getSession(currentSessionId); }

  // Per-project "last open session" key, so returning to a project's AI panel
  // resumes where you left off there specifically, instead of colliding with
  // whatever project was last active.
  function aiSidKey() { return 'sb:ai_sid:' + (selectedProjectId || 'none'); }

  async function persistSession(sess) {
    if (!sess) return;
    // Never store a session that has no messages yet — keeps empty "New session"
    // entries out of the sidebar and the database.
    if (sess._new && (!sess.messages || sess.messages.length === 0)) return;
    try {
      if (sess._new) {
        const oldId = sess.id;
        const created = await Api.post('/v1/ai/sessions', {
          name: sess.name,
          project_id: sess.projectId || undefined,
        });
        sess.id = created.id;
        sess._new = false;
        // The temp id was used as currentSessionId — point it at the real id now.
        if (currentSessionId === oldId) { currentSessionId = sess.id; localStorage.setItem(aiSidKey(), String(sess.id)); }
        // The POST only created the row; push the messages we already have.
        if (sess.messages.length) {
          await Api.patch('/v1/ai/sessions/' + sess.id, { name: sess.name, messages: sess.messages });
        }
      } else {
        await Api.patch('/v1/ai/sessions/' + sess.id, {
          name: sess.name,
          messages: sess.messages,
        });
      }
    } catch(e) { /* non-fatal — UI already updated */ }
  }

  // Project-scoped: pass a projectId for that project's own history, or null
  // for the "Build with AI" bucket (sessions that haven't created a project yet).
  async function loadSessions(projectId) {
    try {
      const qs = projectId ? `?project_id=${projectId}` : '?project_id=none';
      const result = await Api.get('/v1/ai/sessions' + qs);
      sessions = Array.isArray(result) ? result : [];
      sessions.forEach(s => { s.messages = s.messages || []; });
    } catch(e) {
      console.error('[AiPanel] loadSessions failed:', e);
      sessions = [];
    }
  }

  let _tmpSessionSeq = 0;
  function createSession(projectId) {
    // Drop any earlier unsaved empty sessions so they don't pile up in the list.
    sessions = sessions.filter(s => !(s._new && (!s.messages || s.messages.length === 0)));
    const sess = {
      id: 'tmp_' + (++_tmpSessionSeq), _new: true,
      name: 'New session',
      projectId: projectId || null,
      messages: [],
      created_at: new Date().toISOString(),
    };
    sessions.unshift(sess);
    // Not persisted yet — a session is only written once it has its first message.
    return sess;
  }

  async function addMessage(sessionId, message) {
    const sess = getSession(sessionId);
    if (!sess) return;
    sess.messages.push({ ...message, id: 'msg_' + Date.now() + '_' + Math.random().toString(36).slice(2) });
    if (sess.name === 'New session' && message.role === 'user') {
      // Immediate fallback name, then upgrade to an AI-generated title in the background.
      sess.name = message.content.slice(0, 42) + (message.content.length > 42 ? '…' : '');
      nameSessionWithAI(sess, message.content);
    }
    await persistSession(sess);
  }

  // Ensure a session name is unique among sibling sessions by appending (2), (3)…
  function ensureUniqueSessionName(name, sess) {
    const taken = sessions.filter(s => s !== sess).map(s => s.name);
    if (!taken.includes(name)) return name;
    let n = 2;
    while (taken.includes(name + ' (' + n + ')')) n++;
    return name + ' (' + n + ')';
  }

  // Ask the AI for a short, unique title for the session; keep the fallback on failure.
  async function nameSessionWithAI(sess, prompt) {
    if (!sess || sess._aiNamed) return;
    sess._aiNamed = true;
    try {
      const res = await Api.post('/v1/ai/session-title', { prompt });
      let name = (res && res.title || '').trim().slice(0, 60);
      if (!name) return;
      name = ensureUniqueSessionName(name, sess);
      sess.name = name;
      renderSidebar();
      persistSession(sess);
    } catch (_) { /* keep the truncated-prompt fallback */ }
  }

  function saveSessions() {
    const sess = currentSession();
    if (sess) persistSession(sess);
  }

  async function loadProjects() {
    try {
      const result = await Api.get('/v1/projects');
      projects = Array.isArray(result) ? result : [];
    }
    catch(e) { projects = []; }
  }

  function detectCurrentProject() {
    const path = window.location.hash.replace('#', '') || '/';
    const m = path.match(/^\/projects\/(\d+)/);
    return m ? parseInt(m[1]) : null;
  }

  function renderSidebar() {
    if (!panelEl) return;
    const titleEl = panelEl.querySelector('.ai-header-title');
    if (titleEl) {
      const sess = currentSession();
      titleEl.textContent = sess ? (sess.name || 'New session') : '✦ SupaBein AI';
    }
    const container = panelEl.querySelector('.ai-session-list');
    if (!container) return;
    container.innerHTML = '';
    if (!sessions.length) {
      container.appendChild(el('div', { class: 'ai-session-empty' }, 'No sessions yet'));
      return;
    }
    sessions.forEach(sess => {
      const menuDot = el('button', { class: 'ai-session-menu-btn', title: 'Session options' }, '⋮');
      const menuDrop = el('div', { class: 'ai-session-menu-drop hidden' });

      const renameItem = el('div', { class: 'ai-session-menu-item' }, 'Rename');
      renameItem.addEventListener('click', async e => {
        e.stopPropagation();
        menuDrop.classList.add('hidden');
        const newName = prompt('Rename session:', sess.name);
        if (newName && newName.trim()) {
          sess.name = newName.trim();
          await persistSession(sess);
          renderSidebar();
        }
      });

      const deleteItem = el('div', { class: 'ai-session-menu-item ai-session-menu-danger' }, 'Delete');
      deleteItem.addEventListener('click', async e => {
        e.stopPropagation();
        menuDrop.classList.add('hidden');
        try {
          await Api.delete('/v1/ai/sessions/' + sess.id);
          sessions = sessions.filter(s => s.id !== sess.id);
          if (currentSessionId === sess.id) {
            currentSessionId = sessions[0]?.id || null;
            if (currentSessionId) await switchSession(currentSessionId);
            else renderMessages();
          }
          renderSidebar();
        } catch(e) { /* non-fatal */ }
      });

      menuDrop.appendChild(renameItem);
      menuDrop.appendChild(deleteItem);

      menuDot.addEventListener('click', e => {
        e.stopPropagation();
        document.querySelectorAll('.ai-session-menu-drop').forEach(d => { if (d !== menuDrop) d.classList.add('hidden'); });
        menuDrop.classList.toggle('hidden');
      });
      document.addEventListener('click', () => menuDrop.classList.add('hidden'), { once: false, capture: false });

      const item = el('div', {
        class: 'ai-session-item' + (sess.id === currentSessionId ? ' active' : ''),
        onClick: () => {
          switchSession(sess.id);
          if (window.innerWidth < 768) toggleSidebar(false);
        }
      }, el('span', { class: 'ai-session-item-name' }, sess.name), menuDot, menuDrop);
      container.appendChild(item);
    });
  }

  function renderMessages() {
    if (!panelEl) return;
    const container = panelEl.querySelector('.ai-messages');
    if (!container) return;
    container.innerHTML = '';
    const sess = currentSession();
    if (!sess || !sess.messages.length) {
      container.appendChild(el('div', { class: 'ai-welcome' },
        el('div', { class: 'ai-welcome-icon' }, '✦'),
        el('p', {}, 'What do you want to do?'),
        el('p', { class: 'text-muted', style: 'font-size:12px;margin-top:4px' },
          'Build a new project, edit an existing one, or describe a problem.')
      ));
      return;
    }
    sess.messages.forEach(msg => {
      try {
        container.appendChild(renderMessage(msg));
      } catch (err) {
        console.error('[AiPanel] failed to render message', msg?.type, err);
        container.appendChild(el('div', { class: 'ai-msg ai-msg-ai ai-msg-error' }, '✗ Could not display this message.'));
      }
    });
    container.scrollTop = container.scrollHeight;
  }

  // Normalise legacy {actors:string[], stories:string[]} to the nested product-requirements format
  function normalizeIntent(raw) {
    if (!raw) return { actors: [], non_functional_requirements: [] };
    const first = (raw.actors || [])[0];
    if (typeof first === 'string') {
      const storiesArr = (raw.stories || []).map(t => ({ title: t, journeys: [], requirements: [] }));
      return { actors: (raw.actors || []).map(n => ({ name: n, stories: storiesArr })), non_functional_requirements: [] };
    }
    return raw;
  }

  function renderIntentCard(rawIntent, initialName, onConfirm, onCancel) {
    // Deep-clone so edits don't mutate caller's copy
    const intent = JSON.parse(JSON.stringify(normalizeIntent(rawIntent)));
    const actors = intent.actors;
    const nfrs   = intent.non_functional_requirements || [];

    // Project name — the first thing shown, before the intent tree, so
    // naming and intent review happen together as one first step. Click the
    // pencil (or the name itself) to edit; defaults to a guess from the prompt.
    let projectName = (initialName || '').trim();
    const nameRow = el('div', { class: 'ai-intent-name-row' });
    function rebuildNameRow() {
      nameRow.innerHTML = '';
      const span = el('span', { class: 'ai-intent-name-text' }, projectName || 'Untitled project');
      const icon = el('button', { class: 'ai-intent-name-edit', type: 'button', title: 'Edit project name' }, '✎');
      const startEdit = () => {
        const inp = el('input', { class: 'ai-story-inline-input ai-intent-name-input', type: 'text', value: projectName, placeholder: 'Project name', maxlength: '80' });
        nameRow.innerHTML = '';
        nameRow.appendChild(inp);
        inp.focus(); inp.select();
        const commit = () => { projectName = inp.value.trim(); rebuildNameRow(); };
        inp.addEventListener('blur', commit);
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
      };
      icon.addEventListener('click', startEdit);
      span.addEventListener('click', startEdit);
      nameRow.appendChild(icon);
      nameRow.appendChild(span);
    }
    rebuildNameRow();

    const treeWrap = el('div', { class: 'ai-req-tree' });

    function makeInlineEdit(node, getText, setText, onSave) {
      node.addEventListener('click', () => {
        const inp = el('input', { class: 'ai-story-inline-input', type: 'text', value: getText() });
        inp.style.cssText = 'width:100%;min-width:160px';
        node.replaceWith(inp);
        inp.focus(); inp.select();
        const commit = () => { const v = inp.value.trim(); if (v) setText(v); onSave(); };
        inp.addEventListener('blur', commit);
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); commit(); } if (e.key === 'Escape') onSave(); });
      });
    }

    // Render an editable list of plain strings (journeys, requirements, NFRs):
    // each item is click-to-edit with a remove button, plus an add input.
    function buildEditableItems(container, arr, itemClass, placeholder) {
      arr.forEach((val, i) => {
        const span = el('span', { class: 'ai-req-item-text', style: 'flex:1;min-width:0;word-break:break-word' }, val);
        makeInlineEdit(span, () => arr[i], v => { arr[i] = v; }, buildTree);
        container.appendChild(el('div', { class: 'ai-req-item ' + itemClass, style: 'display:flex;align-items:flex-start;gap:6px' },
          el('button', { class: 'ai-chip-remove ai-req-remove', title: 'Remove', onClick: () => { arr.splice(i, 1); buildTree(); } }, '×'),
          span
        ));
      });
      const inp = el('input', { class: 'ai-intent-add-input', placeholder: placeholder || '+ add…', type: 'text' });
      inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') { const v = inp.value.trim(); if (v) { arr.push(v); buildTree(); } }
      });
      container.appendChild(el('div', { class: 'ai-story-add-row' }, inp));
    }

    function buildTree() {
      treeWrap.innerHTML = '';
      actors.forEach((actor, ai) => {
        const actorBlock = el('div', { class: 'ai-req-actor-block' });

        // Actor header row
        const actorNameSpan = el('span', { class: 'ai-req-actor-name' }, actor.name);
        makeInlineEdit(actorNameSpan, () => actor.name, v => { actor.name = v; }, buildTree);
        const actorHdr = el('div', { class: 'ai-req-actor-header' },
          el('span', { class: 'ai-req-tree-bullet' }, '┌─'),
          actorNameSpan,
          el('button', { class: 'ai-chip-remove ai-req-remove', title: 'Remove actor', onClick: () => { actors.splice(ai, 1); buildTree(); } }, '×')
        );
        actorBlock.appendChild(actorHdr);

        // Stories
        const storiesWrap = el('div', { class: 'ai-req-stories-wrap' });
        (actor.stories || []).forEach((story, si) => {
          const isLast = si === (actor.stories.length - 1);
          const storyDet = el('details', { class: 'ai-req-story-block' });
          storyDet.open = true;

          const titleSpan = el('span', { class: 'ai-req-story-title' }, story.title);
          makeInlineEdit(titleSpan, () => story.title, v => { story.title = v; }, buildTree);
          const summary = el('summary', { class: 'ai-req-story-summary' },
            el('span', { class: 'ai-req-tree-connector' }, isLast ? '└─' : '├─'),
            titleSpan,
            el('button', { class: 'ai-chip-remove ai-req-remove', title: 'Remove story',
              onClick: e => { e.stopPropagation(); actor.stories.splice(si, 1); buildTree(); } }, '×')
          );
          storyDet.appendChild(summary);

          const body = el('div', { class: 'ai-req-story-body', style: isLast ? '' : 'border-left:1px solid var(--border)' });

          story.journeys = story.journeys || [];
          story.requirements = story.requirements || [];

          // Journeys (editable)
          body.appendChild(el('div', { class: 'ai-req-section-label' }, 'Journeys'));
          buildEditableItems(body, story.journeys, 'ai-req-journey', '+ add journey…');

          // Functional requirements (editable)
          body.appendChild(el('div', { class: 'ai-req-section-label' }, 'Requirements'));
          buildEditableItems(body, story.requirements, 'ai-req-req', '+ add requirement…');

          storyDet.appendChild(body);
          storiesWrap.appendChild(storyDet);
        });

        // Add story button
        const addStoryInp = el('input', { class: 'ai-intent-add-input', placeholder: '+ add story…', type: 'text' });
        addStoryInp.addEventListener('keydown', e => {
          if (e.key === 'Enter') {
            const v = addStoryInp.value.trim();
            if (v) { actor.stories.push({ title: v, journeys: [], requirements: [] }); buildTree(); }
          }
        });
        storiesWrap.appendChild(el('div', { class: 'ai-story-add-row', style: 'padding-left:20px' }, addStoryInp));

        actorBlock.appendChild(storiesWrap);
        treeWrap.appendChild(actorBlock);
      });

      // Add actor input
      const addActorInp = el('input', { class: 'ai-intent-add-input', placeholder: '+ add actor…', type: 'text' });
      addActorInp.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          const v = addActorInp.value.trim();
          if (v && !actors.find(a => a.name === v)) { actors.push({ name: v, stories: [] }); buildTree(); }
        }
      });
      treeWrap.appendChild(el('div', { style: 'margin-top:6px' }, addActorInp));

      // Non-functional requirements (editable)
      treeWrap.appendChild(el('div', { class: 'ai-intent-divider' }));
      treeWrap.appendChild(el('div', { class: 'ai-req-nfr-header' }, 'Non-Functional Requirements'));
      const nfrList = el('div', { class: 'ai-req-nfr-list' });
      buildEditableItems(nfrList, nfrs, 'ai-req-nfr-item', '+ add non-functional requirement…');
      treeWrap.appendChild(nfrList);
    }

    buildTree();

    return el('div', { class: 'ai-msg ai-msg-ai ai-intent-card' },
      nameRow,
      el('div', { class: 'ai-intent-header' }, 'Product Requirements — review before building'),
      treeWrap,
      el('div', { class: 'ai-intent-actions' },
        el('button', { class: 'btn btn-secondary btn-sm', onClick: onCancel }, 'Cancel'),
        el('button', { class: 'btn btn-ai btn-sm', onClick: () => onConfirm({ actors, non_functional_requirements: nfrs }, projectName || 'Untitled project') }, 'Build with this →')
      )
    );
  }

  // A rough placeholder name so the field isn't empty — just the first few
  // words of the prompt, title-cased. The user is expected to edit it.
  function suggestProjectName(prompt) {
    const words = (prompt || '').trim().split(/\s+/).slice(0, 5);
    if (!words.length) return '';
    return words.map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  }

  function showIntentReviewCard(intent, body) {
    const container = panelEl?.querySelector('.ai-messages');
    if (!container) return;
    const existing = container.querySelector('.ai-intent-card');
    if (existing) existing.remove();

    const card = renderIntentCard(
      intent,
      suggestProjectName(body.prompt),
      async (confirmedIntent, projectName) => {
        card.remove();
        body.intent = { ...confirmedIntent, project_name: projectName };
        // Fire-and-forget — don't block the build start on DB round-trips
        addMessage(currentSessionId, { role: 'ai', type: 'intent', data: confirmedIntent });
        if (selectedProjectId) {
          Api.put('/v1/projects/' + selectedProjectId + '/requirements', confirmedIntent).catch(() => {});
        }
        await proceedWithPlan(body);
      },
      () => { card.remove(); renderMessages(); }
    );
    container.appendChild(card);
    container.scrollTop = container.scrollHeight;
  }

  async function proceedWithPlan(body) {
    let sess = currentSession();
    if (!sess) { sess = await createSession(selectedProjectId); currentSessionId = sess.id; }
    const stageMode = body.project_id ? 'edit' : 'build';

    // Build flow (new app, not chat, not edit) streams its progress live.
    // Review ON: confirm each stage — schema+design pauses for confirmation
    // before frontend generation even starts (see runBuildSchemaDesignStage).
    // Review OFF: watch only — the whole pipeline runs straight through.
    if (!body.chatMode && !body.project_id) {
      // A retry after a failed frontend-stage job carries the already-confirmed
      // schema/design in its retryBody (see runBuildFrontendStage) — resume
      // there instead of redoing schema/design generation from scratch.
      if (body.schema) return runBuildFrontendStage(body.schema, body.design_brief, { prompt: body.prompt });
      return reviewEnabled ? runBuildSchemaDesignStage(body, sess) : runBuildWatchOnly(body, sess);
    }

    const thinkingId = 'thinking_' + Date.now();
    sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode });

    liveTraceMsg = { id: 'trace_' + Date.now(), role: 'ai', type: 'trace', data: [], live: true };
    if (sess) sess.messages.push(liveTraceMsg);
    operationInProgress = true;
    operationMode = stageMode;
    currentAbortController = new AbortController();
    updateSendBtn();
    renderMessages();

    const t0 = Date.now();
    try {
      const response = await callWithFallback('/v1/ai/plan', body, currentAbortController.signal);
      liveTraceMsg.data.push({ call: 'POST /v1/ai/plan', inputs: body, status: 200, outputs: response, ms: Date.now() - t0 });
      renderMessages();
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
      await handlePlanResponse(response);
    } catch(e) {
      if (e instanceof DOMException && e.name === 'AbortError') {
        if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; }
        if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
        operationInProgress = false; operationMode = null; currentAbortController = null;
        updateSendBtn(); renderMessages();
        return;
      }
      if (e.status === 401) { if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; } operationInProgress = false; currentAbortController = null; updateSendBtn(); return; }
      console.error('[AiPanel] /v1/ai/plan failed', { status: e.status, stage: e.data?.stage, code: e.data?.code, error: e });
      liveTraceMsg.data.push({ call: 'POST /v1/ai/plan', inputs: body, status: e.status || 0, outputs: { error: e.message, stage: e.data?.stage, code: e.data?.code, raw: e.data?.raw }, ms: Date.now() - t0 });
      renderMessages();
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
      await addMessage(currentSessionId, { role: 'ai', type: 'error', content: aiFriendlyError(e), retryBody: body, retryType: 'plan' });
    }
    if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; }
    operationInProgress = false;
    operationMode = null;
    currentAbortController = null;
    updateSendBtn();
    if (sess) await persistSession(sess);
    renderSidebar();
    renderMessages();
  }

  // Review-off ("watch only") build: one continuous progress card spanning
  // requirements → schema → design → frontend → validate → deploy → test,
  // with zero manual gates — the only interaction anywhere is the Publish to
  // Live button on the result card once everything's done. Deploy and test
  // now happen SERVER-SIDE as part of the same job (see ai_run_build_and_deploy
  // in ai_routes.php) instead of two more client-driven HTTP calls chained by
  // JS awaits — those didn't survive a reload (a backgrounded mobile tab could
  // resume mid-build and lose the whole chain), a job polled by id does.
  async function runBuildWatchOnly(body, sess) {
    return streamGenerate(body, sess, {
      jobEndpoint: '/v1/ai/build/job',
      stages: BUILD_WATCH_ONLY_STAGES,
      mode: 'build',
      title: 'Building your app',
      onComplete: (ev, progressMsg) => finishBuildWatchOnly(ev, sess, progressMsg),
    });
  }

  // Renders the single result/action card (project, tables, deploy status,
  // Publish/View Staging/Seed buttons) and folds the test+validation results
  // into the 'test' stage's own expandable detail instead of a third card —
  // shared by the live completion path above and the reload-resume path
  // below so both end up in exactly the same two-card state.
  async function finishBuildWatchOnly(ev, sess, progressMsg) {
    const applyResult = ev.apply || {};
    reattachBuildProject(applyResult, sess);
    // Carry the schema/frontend plan and test results onto the result card's
    // own data (not just the progress card's stage details) so its Download
    // JSON button can export the whole build in one file — the job result
    // (ev) already has all of this, it just isn't otherwise attached to the
    // message the result card renders from.
    await addMessage(currentSessionId, { role: 'ai', type: 'result', content: '', data: {
      ...applyResult,
      plan: ev.plan || null,
      test: ev.test || null,
      validation: ev.validation || [],
    } });

    if (!progressMsg) return;
    progressSetStage(progressMsg, 'deploy', 'done');
    const deployStage = progressMsg.data.stages.find(s => s.key === 'deploy');
    if (deployStage) deployStage.rawData = applyResult;
    const testStage = progressMsg.data.stages.find(s => s.key === 'test');
    if (testStage) {
      const t = ev.test;
      progressSetStage(progressMsg, 'test', 'done', t ? `${t.passed || 0} passed, ${t.failed || 0} failed` : 'Nothing to test');
      if (t) {
        testStage.testData = {
          stories: t.stories || [], passed: t.passed || 0, failed: t.failed || 0,
          error: t.error || null, screenshot: t.screenshot || null,
          validation: t.validation || ev.validation || [],
        };
      }
    }
    renderMessages();
  }

  // Same project/session reattachment applyPlan() does for a fresh build —
  // duplicated in miniature here since the watch-only pipeline gets its
  // deploy result directly from the completed job instead of going through
  // applyPlan()/`/v1/ai/apply` itself.
  function reattachBuildProject(result, sess) {
    if (!(result.project?.id && !selectedProjectId)) return;
    const oldKey = aiSidKey();
    selectedProjectId = result.project.id;
    if (sess) sess.projectId = selectedProjectId;
    if (!projects.some(p => String(p.id) === String(selectedProjectId))) projects.push(result.project);
    localStorage.removeItem(oldKey);
    localStorage.setItem(aiSidKey(), currentSessionId);
    Api.patch('/v1/ai/sessions/' + currentSessionId, { project_id: selectedProjectId }).catch(() => {});
    renderProjectPicker();
  }

  function proceedWithEditStreaming(body, sess) {
    return streamGenerate(body, sess, {
      jobEndpoint: '/v1/ai/edit/job',
      stages: EDIT_PROGRESS_STAGES,
      mode: 'edit',
      title: 'Updating your app',
      // Auto-apply the edit straight to staging (no extra Deploy click), then the
      // result card offers View Staging + Publish to Live.
      onComplete: (ev) => applyPlan(ev.plan, 'edit', null, ev.validation),
    });
  }

  // ── Review-on build pipeline: schema+design, then a confirm card, then
  // frontend, reusing the existing plan/apply card as the next confirm gate. ──

  function runBuildSchemaDesignStage(body, sess) {
    return streamGenerate(body, sess, {
      jobEndpoint: '/v1/ai/build-schema/job',
      stages: BUILD_SCHEMA_STAGES,
      mode: 'build_schema',
      title: 'Designing your app',
      onComplete: (ev) => showSchemaDesignReviewCard(ev.schema, ev.design_brief, body),
    });
  }

  function renderSchemaDesignCard(schema, designBrief, onConfirm, onCancel) {
    const tables = schema?.tables || [];
    const designLine = [designBrief?.personality, designBrief?.accent_color].filter(Boolean).join(' · ') || 'default theme';

    return el('div', { class: 'ai-msg ai-msg-ai ai-intent-card' },
      el('div', { class: 'ai-intent-header' }, 'Schema & Design — review before generating frontend'),
      el('div', { class: 'ai-plan-section' }, el('strong', {}, schema?.project_name || 'Untitled project')),
      el('div', { class: 'ai-plan-section', style: 'margin-top:10px' }, `Tables (${tables.length})`),
      ...tables.map(t => el('div', { class: 'ai-plan-item' }, `${t.name} (${(t.columns || []).length} col${(t.columns || []).length !== 1 ? 's' : ''})`)),
      el('div', { class: 'ai-plan-section', style: 'margin-top:10px' }, 'Design: ' + designLine),
      el('div', { class: 'ai-intent-actions', style: 'margin-top:14px' },
        el('button', { class: 'btn btn-secondary btn-sm', onClick: onCancel }, 'Cancel'),
        el('button', { class: 'btn btn-ai btn-sm', onClick: onConfirm }, 'Confirm → Generate Frontend')
      )
    );
  }

  function showSchemaDesignReviewCard(schema, designBrief, body) {
    const container = panelEl?.querySelector('.ai-messages');
    if (!container) return;
    const card = renderSchemaDesignCard(
      schema, designBrief,
      async () => { card.remove(); await runBuildFrontendStage(schema, designBrief, body); },
      () => { card.remove(); renderMessages(); }
    );
    container.appendChild(card);
    container.scrollTop = container.scrollHeight;
  }

  async function runBuildFrontendStage(schema, designBrief, body) {
    let sess = currentSession();
    if (!sess) { sess = await createSession(selectedProjectId); currentSessionId = sess.id; }
    const jobBody = { prompt: body.prompt, schema, design_brief: designBrief };
    return streamGenerate(jobBody, sess, {
      jobEndpoint: '/v1/ai/build-frontend/job',
      stages: BUILD_FRONTEND_STAGES,
      mode: 'build_frontend',
      title: 'Generating frontend code',
      onComplete: (ev) => handlePlanResponse({ mode: 'build', plan: ev.plan, summary: ev.summary, usage: ev.usage, validation: ev.validation }),
    });
  }

  // Poll a job's progress until it resolves, replaying new stage events onto
  // the live progress card exactly like the old NDJSON stream did. Runs as a
  // server-side job (see ai_worker.php) independent of this page's lifetime —
  // reloading and reopening the panel just calls this again with the same
  // jobId (see resumeActiveJob below), picking up wherever it left off.
  async function pollJob(jobId, progressMsg, pollState, sess) {
    let since = 0;
    let consecutiveFailures = 0;
    const MAX_CONSECUTIVE_FAILURES = 15; // ~30s of nothing-but-errors — give up rather than spin forever
    while (!pollState.cancelled) {
      await new Promise(r => setTimeout(r, 2000));
      if (pollState.cancelled) return null;

      let job;
      try {
        job = await Api.get(`/v1/ai/jobs/${jobId}?since=${since}`);
        consecutiveFailures = 0;
      } catch (e) {
        if (e.status === 401 || e.status === 404) return null;
        consecutiveFailures++;
        if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
          progressMsg.data.jobDone = true;
          const active = progressMsg.data.stages.find(s => s.status === 'active');
          if (active) active.status = 'error';
          progressMsg.data.error = 'Lost connection to the server — please try again.';
          return null;
        }
        continue; // transient network hiccup — keep polling
      }

      const hadNewEvents = job.events && job.events.length > 0;
      (job.events || []).forEach(ev => applyProgressEvent(progressMsg, ev));
      since = job.event_count;
      renderMessages();
      // Keep the persisted snapshot current so leaving mid-job and coming back
      // shows the real stage instead of whatever was last saved at job-start.
      if (hadNewEvents && sess) persistSession(sess).catch(() => {});

      if (job.status === 'done') {
        progressMsg.data.jobDone = true;
        attachTraceToStages(progressMsg, job.result?.aiTrace);
        return { stage: 'complete', ...job.result };
      }
      if (job.status === 'failed' || job.status === 'cancelled') {
        progressMsg.data.jobDone = true;
        const active = progressMsg.data.stages.find(s => s.status === 'active');
        if (active) active.status = 'error';
        progressMsg.data.error = job.error || (job.status === 'cancelled' ? 'Stopped' : 'The job failed — please try again.');
        console.error('[AiPanel] job resolved as ' + job.status, { jobId, error: progressMsg.data.error });
        return null;
      }
    }
    return null;
  }

  // Shared driver for build and edit: creates a server-side job, shows a live
  // progress card, polls until the job resolves, then hands the final plan to
  // opts.onComplete.
  async function streamGenerate(body, sess, opts) {
    const progressMsg = makeProgressMsg(opts.stages, opts.title, opts.mode);
    // Stashed so a reload mid-job can reconstruct enough of the original
    // request to resume correctly (see resumeActiveJobIfAny) — only the
    // build_schema stage actually needs this today, to hand the prompt back
    // to the schema/design confirm card once that job finishes.
    progressMsg.data.resumePrompt = body.prompt;
    sess.messages.push(progressMsg);

    liveTraceMsg = { id: 'trace_' + Date.now(), role: 'ai', type: 'trace', data: [], live: true };
    sess.messages.push(liveTraceMsg);
    operationInProgress = true;
    operationMode = opts.mode;
    updateSendBtn();
    renderMessages();

    // Persist BEFORE creating the job — a session that's still a local "tmp_"
    // id at this point would make the job's session_id silently come back
    // null (the check below only attaches it when the id is already real),
    // permanently orphaning the job from this session in the database.
    await persistSession(sess);

    const { provider, model } = getSelectedModel();
    const t0 = Date.now();

    let jobId;
    try {
      const jobBody = { ...body, provider, model };
      if (sess.id && !String(sess.id).startsWith('tmp_')) jobBody.session_id = sess.id;
      const created = await Api.post(opts.jobEndpoint, jobBody);
      jobId = created.job_id;
    } catch (e) {
      console.error('[AiPanel] job creation failed', { jobEndpoint: opts.jobEndpoint, error: e });
      const active = progressMsg.data.stages.find(s => s.status === 'active');
      if (active) active.status = 'error';
      liveTraceMsg.data.push({ call: 'POST ' + opts.jobEndpoint, inputs: body, status: e.status || 0, outputs: { error: e.message }, ms: Date.now() - t0 });
      await addMessage(currentSessionId, { role: 'ai', type: 'error', content: aiFriendlyError(e), retryBody: body, retryType: 'plan' });
      if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; }
      operationInProgress = false; operationMode = null;
      updateSendBtn();
      await persistSession(sess);
      renderSidebar(); renderMessages();
      return;
    }

    // Persist right away — this, not anything at the end, is what makes the
    // job resumable: a reload a second from now still has the jobId to
    // reconnect to, purely from what's on the server.
    progressMsg.data.jobId = jobId;
    const pollState = { cancelled: false };
    activeJobPoll = pollState;
    renderMessages();
    await persistSession(sess);

    const finalEv = await pollJob(jobId, progressMsg, pollState, sess);
    if (activeJobPoll === pollState) activeJobPoll = null;

    if (!finalEv) {
      if (pollState.cancelled) {
        // user-initiated stop — clean up silently, same as the old abort path
        sess.messages = sess.messages.filter(m => m.id !== progressMsg.id);
      } else {
        const emsg = progressMsg.data.error || 'The job failed — please try again.';
        console.error('[AiPanel] job failed', { jobEndpoint: opts.jobEndpoint, jobId, error: emsg });
        liveTraceMsg.data.push({ call: 'GET /v1/ai/jobs/' + jobId, inputs: {}, status: 0, outputs: { error: emsg }, ms: Date.now() - t0 });
        await addMessage(currentSessionId, { role: 'ai', type: 'error', content: emsg, retryBody: body, retryType: 'plan' });
      }
    } else {
      liveTraceMsg.data.push({ call: 'GET /v1/ai/jobs/' + jobId, inputs: {}, status: 200, outputs: { mode: finalEv.mode, summary: finalEv.summary, usage: finalEv.usage, aiTrace: finalEv.aiTrace }, ms: Date.now() - t0 });
      renderMessages();
      try {
        await opts.onComplete(finalEv, progressMsg);
      } catch (e) {
        console.error('[AiPanel] onComplete handler failed', { jobEndpoint: opts.jobEndpoint, jobId, error: e });
        progressSetStage(progressMsg, progressMsg.data.stages.find(s => s.status === 'active')?.key, 'error', e.message);
        await addMessage(currentSessionId, { role: 'ai', type: 'error', content: e.message || 'Something went wrong after generation finished.', retryBody: body, retryType: 'plan' });
      }
    }

    if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; }
    operationInProgress = false;
    operationMode = null;
    updateSendBtn();
    await persistSession(sess);
    renderSidebar();
    renderMessages();
  }

  // Called when the panel opens or a session is switched to: if the loaded
  // messages include a build/edit/test job that never resolved (e.g. the page
  // reloaded mid-operation), resume polling it right where it left off —
  // this is what makes reload/rotate/close-the-tab safe.
  function resumeActiveJobIfAny(sess) {
    if (!sess || activeJobPoll) return;
    const progressMsg = sess.messages.find(m => m.type === 'progress' && m.data.jobId && !m.data.jobDone);
    if (!progressMsg) return;

    operationInProgress = true;
    operationMode = progressMsg.data.mode;
    updateSendBtn();

    const pollState = { cancelled: false };
    activeJobPoll = pollState;
    const onComplete = operationMode === 'build'
      ? (ev) => finishBuildWatchOnly(ev, sess, progressMsg)
      : operationMode === 'build_frontend'
      ? (ev) => handlePlanResponse({ mode: 'build', plan: ev.plan, summary: ev.summary, usage: ev.usage, validation: ev.validation })
      : operationMode === 'build_schema'
      ? (ev) => showSchemaDesignReviewCard(ev.schema, ev.design_brief, { prompt: progressMsg.data.resumePrompt })
      : operationMode === 'test'
      ? (ev) => { sess.messages.push({ id: 'test_' + Date.now(), role: 'ai', type: 'test', data: {
          stories: ev.stories || [], passed: ev.passed || 0, failed: ev.failed || 0,
          error: ev.error || null, screenshot: ev.screenshot || null, validation: ev.validation || [],
        }}); }
      : operationMode === 'edit'
      ? (ev) => applyPlan(ev.plan, 'edit', null, ev.validation)
      : (ev) => {}; // 'seed' has no follow-up UI beyond the progress card itself

    if (!liveTraceMsg) {
      liveTraceMsg = sess.messages.find(m => m.type === 'trace' && m.live) || { id: 'trace_' + Date.now(), role: 'ai', type: 'trace', data: [], live: true };
      if (!sess.messages.includes(liveTraceMsg)) sess.messages.push(liveTraceMsg);
    }

    (async () => {
      const finalEv = await pollJob(progressMsg.data.jobId, progressMsg, pollState, sess);
      if (activeJobPoll === pollState) activeJobPoll = null;
      if (finalEv) await onComplete(finalEv);
      if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; }
      operationInProgress = false;
      operationMode = null;
      updateSendBtn();
      await persistSession(sess);
      renderSidebar();
      renderMessages();
    })();
  }

  async function proceedWithBuildDirect(body) {
    let sess = currentSession();
    if (!sess) { sess = await createSession(selectedProjectId); currentSessionId = sess.id; }

    // Both new builds and edits run as a job-backed generation with a live
    // progress card. This is only ever reached with Review off (see
    // sendMessage), so both run straight through — edits auto-apply+test as
    // before; builds use the unified 7-stage watch-only pipeline, which
    // handles its own deploy+test instead of going through handlePlanResponse.
    return body.project_id
      ? proceedWithEditStreaming(body, sess)
      : runBuildWatchOnly(body, sess);
  }

  async function handlePlanResponse(response) {
    if (!response || !response.mode) {
      await addMessage(currentSessionId, { role: 'ai', type: 'error', content: 'The AI returned an empty or unreadable response — the server may have timed out or the response was too large. Please try again.' });
      return;
    }
    if (response.mode === 'chat') {
      await addMessage(currentSessionId, { role: 'ai', type: 'chat', content: response.message, usage: response.usage });
    } else if (response.mode === 'diagnose') {
      await addMessage(currentSessionId, { role: 'ai', type: 'diagnosis', content: '', data: response });
    } else if (response.mode === 'build' && !reviewEnabled) {
      // Review off = watch only: deploy to staging straight away, no manual
      // Apply click — the only gate anyone gets is the final Publish to Live.
      await applyPlan(response.plan, 'build', null, response.validation);
    } else if (response.mode === 'build' || response.mode === 'edit') {
      await addMessage(currentSessionId, { role: 'ai', type: 'plan', content: '', data: response, settled: false });
    } else {
      await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Unexpected response from AI (mode: ${response.mode}) — try rephrasing your request.` });
    }
  }

  function renderIntentSummaryCard(msg) {
    // Read-only collapsible view of the confirmed product requirements.
    // Handles both the legacy flat format and the nested format.
    const intent = normalizeIntent(msg.data || {});
    const actors = (intent.actors || []).map(a => (typeof a === 'string' ? { name: a, stories: [] } : a));
    const nfrs = (intent.non_functional_requirements || []).filter(Boolean);

    const card = el('div', { class: 'ai-msg ai-msg-ai ai-intent-summary' },
      el('div', { class: 'ai-intent-summary-header' }, '✓ Intent confirmed')
    );

    // Actors row (always visible)
    const actorNames = actors.map(a => a.name).filter(Boolean);
    if (actorNames.length) {
      card.appendChild(el('div', { class: 'ai-intent-summary-actors', style: 'margin-bottom:6px' },
        el('span', { class: 'ai-intent-section-label', style: 'margin-right:6px' }, 'Actors:'),
        ...actorNames.map(a => el('span', { class: 'ai-actor-chip' }, a))
      ));
    }

    const itemList = (label, arr) => {
      if (!arr || !arr.length) return null;
      return el('div', { style: 'margin:4px 0 4px 10px' },
        el('div', { class: 'ai-req-section-label' }, label),
        ...arr.filter(Boolean).map(x => el('div', { class: 'ai-req-item' }, '• ' + (typeof x === 'string' ? x : '')))
      );
    };

    // Per-story collapsible sections with journeys + requirements
    actors.forEach(a => {
      (a.stories || []).forEach(story => {
        const title = typeof story === 'string' ? story : (story.title || '');
        if (!title) return;
        const journeys = (story && story.journeys) || [];
        const reqs = (story && story.requirements) || [];
        const det = el('details', { class: 'ai-intent-summary-section' });
        det.appendChild(el('summary', { class: 'ai-intent-summary-story' }, '• ' + title));
        const body = el('div', { style: 'padding:4px 0 6px 6px' });
        const j = itemList('Journeys', journeys);
        const r = itemList('Requirements', reqs);
        if (j) body.appendChild(j);
        if (r) body.appendChild(r);
        if (!j && !r) body.appendChild(el('div', { class: 'ai-req-item', style: 'color:var(--muted)' }, 'No further detail'));
        det.appendChild(body);
        card.appendChild(det);
      });
    });

    // Non-functional requirements (collapsible)
    if (nfrs.length) {
      const det = el('details', { class: 'ai-intent-summary-section' });
      det.appendChild(el('summary', { class: 'ai-req-nfr-header', style: 'cursor:pointer' }, 'Non-Functional Requirements'));
      const body = el('div', { style: 'padding:4px 0 6px 6px' });
      nfrs.forEach(r => body.appendChild(el('div', { class: 'ai-req-item' }, '• ' + r)));
      det.appendChild(body);
      card.appendChild(det);
    }

    return card;
  }

  function renderRecoveryCard(msg) {
    const data = msg.data || {};
    const options = data.options || [];
    const optionBtns = [];

    options.forEach(opt => {
      const labelEl = el('div', { class: 'ai-recovery-option-label' }, opt.label || '');
      const btn = el('button', { class: 'ai-recovery-option' },
        labelEl,
        el('div', { class: 'ai-recovery-option-desc' }, opt.description || '')
      );
      btn.addEventListener('click', async () => {
        optionBtns.forEach(b => { b.disabled = true; b.style.opacity = '0.5'; });
        labelEl.textContent = '⏳ Applying…';
        await applyPlan(opt.plan, 'build');
      });
      optionBtns.push(btn);
    });

    const tokenEl = renderTokenUsage(data.usage || msg.usage);

    return el('div', { class: 'ai-msg ai-msg-ai ai-recovery-card' },
      el('div', { class: 'ai-recovery-header' }, '⚠ Build failed — here\'s what I can do:'),
      el('div', { class: 'ai-recovery-diagnosis' }, data.diagnosis || ''),
      ...(optionBtns.length ? [
        el('div', { class: 'ai-recovery-options-label' }, 'Choose an option:'),
        el('div', { class: 'ai-recovery-options' }, ...optionBtns),
      ] : []),
      ...(tokenEl ? [tokenEl] : [])
    );
  }

  function renderEditReviewCard(suggestions, onConfirm, onCancel) {
    const confirmed = new Set(suggestions.map(s => s.id));
    const customItems = [];

    const listEl = el('div', { class: 'ai-edit-review-list' });

    function refreshList() {
      listEl.innerHTML = '';
      suggestions.forEach(s => {
        const labelSpan = el('span', { class: 'ai-edit-review-label' }, s.label);
        labelSpan.addEventListener('click', () => {
          const inp = el('input', { class: 'ai-edit-review-inline-input', type: 'text' });
          inp.value = s.label;
          labelSpan.replaceWith(inp);
          inp.focus(); inp.select();
          const save = () => {
            const v = inp.value.trim();
            if (v) s.label = v;
            refreshList();
          };
          inp.addEventListener('blur', save);
          inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); save(); }
            if (e.key === 'Escape') refreshList();
          });
        });

        const cb = el('input', { class: 'ai-edit-review-check', type: 'checkbox' });
        cb.checked = confirmed.has(s.id);
        cb.addEventListener('change', () => { if (cb.checked) confirmed.add(s.id); else confirmed.delete(s.id); });

        listEl.appendChild(el('div', { class: 'ai-edit-review-item' },
          cb,
          el('div', { class: 'ai-edit-review-text' },
            labelSpan,
            el('span', { class: 'ai-edit-review-desc' }, s.description || '')
          )
        ));
      });

      customItems.forEach((item, ci) => {
        const cb = el('input', { class: 'ai-edit-review-check', type: 'checkbox' });
        cb.checked = true;
        cb.addEventListener('change', () => {
          if (!cb.checked) { customItems.splice(ci, 1); refreshList(); }
        });
        listEl.appendChild(el('div', { class: 'ai-edit-review-item' },
          cb,
          el('div', { class: 'ai-edit-review-text' },
            el('span', { class: 'ai-edit-review-label' }, item.label)
          )
        ));
      });
    }

    refreshList();

    const addInp = el('input', { class: 'ai-intent-add-input', placeholder: '+ add your own change…', type: 'text', style: 'margin-top:8px;width:100%' });
    addInp.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        const v = addInp.value.trim();
        if (v) { customItems.push({ id: 'custom_' + Date.now(), label: v, description: '' }); addInp.value = ''; refreshList(); }
      }
    });

    return el('div', { class: 'ai-msg ai-msg-ai ai-edit-review-card' },
      el('div', { class: 'ai-intent-header' }, 'Edit Review — select changes to apply'),
      listEl,
      addInp,
      el('div', { class: 'ai-intent-actions' },
        el('button', { class: 'btn btn-secondary btn-sm', onClick: onCancel }, 'Cancel'),
        el('button', { class: 'btn btn-ai btn-sm', onClick: () => {
          const selected = [
            ...suggestions.filter(s => confirmed.has(s.id)),
            ...customItems
          ];
          if (!selected.length) return;
          onConfirm(selected);
        }}, 'Apply selected →')
      )
    );
  }

  function showEditReviewCard(suggestions, body) {
    addMessage(currentSessionId, {
      role: 'ai',
      type: 'edit-review',
      data: { suggestions, body },
      settled: false,
    });
    renderMessages();
  }

  function renderEditReviewInlineCard(msg) {
    if (msg.settled) {
      if (msg.cancelled) {
        return el('div', { class: 'ai-msg ai-msg-ai ai-edit-review-card ai-plan-settled' },
          el('div', { class: 'ai-intent-header' }, 'Edit review — cancelled')
        );
      }
      const selected = msg.data?.finalSelected || [];
      return el('div', { class: 'ai-msg ai-msg-ai ai-edit-review-card ai-plan-settled' },
        el('div', { class: 'ai-intent-header' }, '✓ Edit review — confirmed'),
        el('div', { class: 'ai-intent-summary-stories' },
          ...selected.map(s => el('div', { class: 'ai-intent-summary-story' }, '• ' + s.label))
        )
      );
    }
    return renderEditReviewCard(
      msg.data?.suggestions || [],
      async (selected) => {
        msg.data.finalSelected = selected;
        msg.settled = true;
        saveSessions();
        renderMessages();
        await addMessage(currentSessionId, { role: 'ai', type: 'edit-intent', data: { confirmed: selected, original_prompt: msg.data.body?.prompt } });
        const refinedPrompt = (msg.data.body?.prompt || '')
          + '\n\nApply ONLY these specific changes (ignore everything else):\n'
          + selected.map((s, i) => `${i + 1}. ${s.label}`).join('\n');
        await proceedWithBuildDirect({ ...msg.data.body, prompt: refinedPrompt });
      },
      () => {
        msg.settled = true;
        msg.cancelled = true;
        saveSessions();
        renderMessages();
      }
    );
  }

  function renderEditIntentSummaryCard(msg) {
    const data = msg.data || {};
    const confirmed = data.confirmed || [];
    return el('div', { class: 'ai-msg ai-msg-ai ai-intent-summary' },
      el('div', { class: 'ai-intent-summary-header' }, '✓ Edit changes confirmed'),
      el('div', { class: 'ai-intent-summary-stories' },
        ...confirmed.map(s => el('div', { class: 'ai-intent-summary-story' }, '• ' + s.label))
      )
    );
  }

  let seedRunning = false;
  const SEED_PROGRESS_STAGES = [
    { key: 'schema',   label: 'Reading schema' },
    { key: 'accounts', label: 'Setting up test login accounts' },
    { key: 'generate', label: 'Generating sample data' },
    { key: 'insert',   label: 'Inserting rows' },
  ];

  // Seed the project's tables with realistic sample data on demand — same
  // background-job machinery as Run Tests, just a simpler job with no cards
  // of its own; the outcome is reported as a plain chat message.
  async function runProjectSeed(projectId, btn) {
    if (!projectId || seedRunning) return;
    seedRunning = true;
    if (btn) btn.disabled = true;
    let sess = currentSession();
    if (!sess) { sess = await createSession(projectId); currentSessionId = sess.id; }

    const progressMsg = makeProgressMsg(SEED_PROGRESS_STAGES, 'Seeding your app', 'seed');
    sess.messages.push(progressMsg);
    operationInProgress = true;
    operationMode = 'seed';
    updateSendBtn();
    renderMessages();

    // Persist BEFORE creating the job so a brand-new session's tmp_ id has
    // already become a real id — otherwise session_id below stays unset and
    // the job is permanently orphaned from this session.
    await persistSession(sess);

    try {
      const jobBody = { project_id: projectId };
      if (sess.id && !String(sess.id).startsWith('tmp_')) jobBody.session_id = sess.id;
      const created = await Api.post('/v1/ai/seed/job', jobBody);
      progressMsg.data.jobId = created.job_id;
      const pollState = { cancelled: false };
      activeJobPoll = pollState;
      await persistSession(sess);

      const finalEv = await pollJob(created.job_id, progressMsg, pollState, sess);
      if (activeJobPoll === pollState) activeJobPoll = null;

      if (finalEv) {
        await addMessage(currentSessionId, { role: 'ai', type: 'chat', content: formatSeedResultMessage(finalEv) });
      } else if (!pollState.cancelled) {
        await addMessage(currentSessionId, { role: 'ai', type: 'error', content: progressMsg.data.error || 'Seeding failed' });
      }
    } catch (err) {
      console.error('[AiPanel] Seed App failed', err);
      const active = progressMsg.data.stages.find(s => s.status === 'active');
      if (active) active.status = 'error';
      progressMsg.data.jobDone = true;
      await addMessage(currentSessionId, { role: 'ai', type: 'error', content: err.message });
    } finally {
      seedRunning = false;
      operationInProgress = false;
      operationMode = null;
      if (btn) btn.disabled = false;
      updateSendBtn();
      await persistSession(sess);
      renderMessages();
      const c = panelEl?.querySelector('.ai-messages');
      if (c) c.scrollTop = c.scrollHeight;
    }
  }

  let testRunning = false;
  // Run the Playwright user-story tests for a project as a background job with
  // a live progress card — same machinery as build/edit, so the run survives
  // closing the panel or reloading the page (the old synchronous request lost
  // the result if you navigated away during the 30-60s a run takes).
  async function runProjectTests(projectId, btn, auto = false) {
    if (!projectId || testRunning) return;
    testRunning = true;
    if (btn) btn.disabled = true;
    let sess = currentSession();
    if (!sess) { sess = await createSession(projectId); currentSessionId = sess.id; }
    if (!auto) await addMessage(currentSessionId, { role: 'user', content: 'Run tests' });

    const progressMsg = makeProgressMsg(TEST_PROGRESS_STAGES, auto ? 'Auto-testing your app' : 'Testing your app', 'test');
    sess.messages.push(progressMsg);
    operationInProgress = true;
    operationMode = 'test';
    updateSendBtn();
    renderMessages();

    // Persist BEFORE creating the job so a brand-new session's tmp_ id has
    // already become a real id — otherwise session_id below stays unset and
    // the job is permanently orphaned from this session (this was the cause
    // of test/build jobs seeming to land in "a new session" when they were
    // the very first action taken in a freshly-opened panel).
    await persistSession(sess);

    try {
      const { provider, model } = getSelectedModel();
      const jobBody = { project_id: projectId, provider, model };
      if (sess.id && !String(sess.id).startsWith('tmp_')) jobBody.session_id = sess.id;
      const created = await Api.post('/v1/ai/test/job', jobBody);
      progressMsg.data.jobId = created.job_id;
      const pollState = { cancelled: false };
      activeJobPoll = pollState;
      await persistSession(sess);

      const finalEv = await pollJob(created.job_id, progressMsg, pollState, sess);
      if (activeJobPoll === pollState) activeJobPoll = null;

      if (finalEv) {
        sess.messages.push({ id: 'test_' + Date.now(), role: 'ai', type: 'test', data: {
          stories: finalEv.stories || [], passed: finalEv.passed || 0, failed: finalEv.failed || 0,
          error: finalEv.error || null, screenshot: finalEv.screenshot || null,
          validation: finalEv.validation || [],
        }});
      } else if (!pollState.cancelled) {
        sess.messages.push({ id: 'test_err_' + Date.now(), role: 'ai', type: 'test',
          data: { stories: [], passed: 0, failed: 0, error: progressMsg.data.error || 'Test run failed' } });
      }
    } catch (err) {
      console.error('[AiPanel] Run Tests failed', err);
      const active = progressMsg.data.stages.find(s => s.status === 'active');
      if (active) active.status = 'error';
      progressMsg.data.jobDone = true;
      sess.messages.push({ id: 'test_err_' + Date.now(), role: 'ai', type: 'test',
        data: { stories: [], passed: 0, failed: 0, error: err.message } });
    } finally {
      testRunning = false;
      operationInProgress = false;
      operationMode = null;
      if (btn) btn.disabled = false;
      updateSendBtn();
      await persistSession(sess);
      renderMessages();
      const c = panelEl?.querySelector('.ai-messages');
      if (c) c.scrollTop = c.scrollHeight;
    }
  }

  // Turns a failing test+validation result into an edit-mode prompt asking
  // the AI to fix exactly those problems — reuses the normal edit pipeline
  // (schema-aware generation, self-heal retry, validate, deploy) instead of
  // a bespoke fix path, so a "Resolve" is just as reliable as a manual edit.
  function buildResolvePrompt(data) {
    const parts = [];
    const failedStories = (data.stories || []).filter(s => !s.passed);
    if (failedStories.length) {
      parts.push('Fix these failing test stories:\n' + failedStories.map(s =>
        `- ${s.label}` + (s.detail ? `: ${s.detail}` : '')).join('\n'));
    }
    const issues = (data.validation || []).filter(f => f.severity === 'error' || f.severity === 'warning');
    if (issues.length) {
      parts.push('Fix these validation issues:\n' + issues.map(f =>
        `- [${f.severity}] ${f.message}` + (f.explanation ? ` — ${f.explanation}` : '')).join('\n'));
    }
    if (!parts.length) return '';
    return 'Automated testing and validation found problems with this app.\n\n' + parts.join('\n\n')
      + '\n\nFix all of the above without changing anything else.';
  }

  async function resolveIssues(data, btn) {
    const prompt = buildResolvePrompt(data);
    if (!prompt || !panelEl) return;
    const textarea = panelEl.querySelector('#ai-textarea');
    if (!textarea) return;
    textarea.value = prompt;
    if (btn) btn.disabled = true;
    try {
      await sendMessage();
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function downloadJson(filename, obj) {
    const blob = new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  function renderTestCard(msg) {
    const { stories = [], passed = 0, failed = 0, error, screenshot, validation = [] } = msg.data || {};
    const total = passed + failed;
    const statusColor = error ? 'var(--danger)' : total === 0 ? 'var(--text-muted)' : failed === 0 ? '#22c55e' : 'var(--danger)';
    const statusText  = error
      ? `Error: ${error}`
      : total === 0 ? 'No stories generated'
      : failed === 0 ? `All ${passed} passed ✓`
      : `${passed}/${total} passed · ${failed} failed`;

    const card = el('div', { class: 'ai-msg ai-msg-ai', style: 'padding:0;overflow:hidden' });

    const hdr = el('div', { style: 'padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)' },
      el('span', { style: 'font-weight:600;font-size:0.875rem' }, '▶ Test Results'),
      el('span', { style: `color:${statusColor};font-size:0.8rem;font-weight:600` }, statusText)
    );
    card.appendChild(hdr);

    if (stories.length) {
      const list = el('div', { style: 'padding:10px 14px;display:flex;flex-direction:column;gap:4px' });
      stories.forEach(s => {
        const icon  = s.passed ? '✓' : '✗';
        const color = s.passed ? '#22c55e' : 'var(--danger)';
        if (!s.passed && s.detail) {
          const det = el('details', { style: 'font-size:0.8rem' });
          det.appendChild(el('summary', { style: `display:flex;align-items:baseline;gap:8px;cursor:pointer;list-style:none;padding:1px 0;color:var(--danger)` },
            el('span', { style: 'color:var(--danger);font-weight:700;flex-shrink:0' }, '✗'),
            el('span', {}, s.label)
          ));
          det.appendChild(el('div', { style: 'margin:4px 0 4px 18px;font-size:0.72rem;color:var(--text-muted);background:rgba(239,68,68,0.06);padding:6px 8px;border-radius:4px;border-left:2px solid var(--danger);white-space:pre-wrap;word-break:break-all' }, s.detail));
          list.appendChild(det);
        } else {
          list.appendChild(el('div', { style: 'display:flex;align-items:baseline;gap:8px;font-size:0.8rem' },
            el('span', { style: `color:${color};font-weight:700;flex-shrink:0` }, icon),
            el('span', { style: `color:${s.passed ? 'var(--text)' : 'var(--danger)'}` }, s.label)
          ));
        }
      });
      card.appendChild(list);
    }

    if (screenshot) {
      const det = el('details', { style: 'border-top:1px solid var(--border);padding:10px 14px;min-width:0' });
      det.appendChild(el('summary', { style: 'font-size:0.8rem;cursor:pointer;color:var(--text-muted)' }, 'Screenshot'));
      const src = 'data:image/png;base64,' + screenshot;
      const img = el('img', { title: 'Tap to open full size',
        style: 'display:block;margin-top:8px;width:100%;max-width:100%;height:auto;border-radius:4px;border:1px solid var(--border);cursor:zoom-in' });
      img.src = src;
      // Open the full-resolution capture in a new tab so nothing is lost to scaling.
      img.addEventListener('click', () => { const w = window.open(); if (w) w.document.write('<img src="' + src + '" style="max-width:100%">'); });
      det.appendChild(img);
      det.appendChild(el('div', { style: 'margin-top:4px;font-size:0.7rem;color:var(--text-muted)' }, 'Tap image to open full size'));
      card.appendChild(det);
    }

    const errCount  = validation.filter(f => f.severity === 'error').length;
    const warnCount = validation.filter(f => f.severity === 'warning').length;
    const validationStatus = !validation.length ? 'No issues found ✓'
      : `${errCount} error${errCount !== 1 ? 's' : ''} · ${warnCount} warning${warnCount !== 1 ? 's' : ''}`;
    const validationColor = errCount ? 'var(--danger)' : warnCount ? '#f59e0b' : '#22c55e';
    card.appendChild(el('div', { style: 'padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border)' },
      el('span', { style: 'font-weight:600;font-size:0.875rem' }, '⚑ Validation'),
      el('span', { style: `color:${validationColor};font-size:0.8rem;font-weight:600` }, validationStatus)
    ));
    if (validation.length) {
      card.appendChild(el('div', { style: 'padding:10px 14px;border-top:1px solid var(--border)' },
        renderValidationExplainer(), renderValidationList(validation)));
    }

    const hasIssues = failed > 0 || validation.some(f => f.severity === 'error' || f.severity === 'warning');
    const cardActions = el('div', { style: 'padding:10px 14px;display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid var(--border)' });
    const downloadBtn = el('button', { class: 'btn btn-secondary btn-sm' }, '⭳ Download JSON');
    downloadBtn.addEventListener('click', () => downloadJson(`sb-report-${msg.id || Date.now()}.json`,
      { stories, passed, failed, error: error || null, validation }));
    cardActions.appendChild(downloadBtn);
    if (hasIssues) {
      const resolveBtn = el('button', { class: 'btn btn-ai btn-sm' }, '🛠 Resolve');
      resolveBtn.title = 'Ask the AI to fix these failing stories and validation issues';
      resolveBtn.addEventListener('click', () => resolveIssues(msg.data, resolveBtn));
      cardActions.appendChild(resolveBtn);
    }
    card.appendChild(cardActions);

    return card;
  }

  const VALIDATION_SEVERITY_STYLE = {
    error:   { color: 'var(--danger)', icon: '✗' },
    warning: { color: '#f59e0b',       icon: '⚠' },
    info:    { color: 'var(--text-muted)', icon: 'ℹ' },
  };

  // Explains what "Validation" actually checks — shown collapsed so it
  // doesn't clutter the card, but discoverable for anyone unsure what the
  // severities mean or why this is separate from the browser test results.
  function renderValidationExplainer() {
    return el('details', { style: 'font-size:0.72rem;color:var(--text-muted);margin-bottom:6px' },
      el('summary', { style: 'cursor:pointer' }, 'What is this?'),
      el('div', { style: 'margin-top:6px;line-height:1.5' },
        'This is a deterministic code check — not an AI opinion. It scans the generated ' +
        'frontend against your database schema for concrete mismatches: routes with no page, ' +
        'nav links that go nowhere, seed data columns the UI never reads, and similar wiring bugs. ',
        el('br'), el('br'),
        el('span', { style: `color:${VALIDATION_SEVERITY_STYLE.error.color};font-weight:700` }, '✗ Error'), ' — almost certainly a real bug, worth fixing. ',
        el('br'),
        el('span', { style: `color:${VALIDATION_SEVERITY_STYLE.warning.color};font-weight:700` }, '⚠ Warning'), ' — worth a look, but may be intentional. ',
        el('br'),
        el('span', { style: `color:${VALIDATION_SEVERITY_STYLE.info.color};font-weight:700` }, 'ℹ Info'), ' — for awareness only, no action needed.'
      )
    );
  }

  // Shared by the standalone validation card (edit mode, after auto-deploy)
  // and the plan card's validation section (build mode, before the user applies).
  function renderValidationList(findings) {
    const list = el('div', { style: 'display:flex;flex-direction:column;gap:4px' });
    findings.forEach(f => {
      const style = VALIDATION_SEVERITY_STYLE[f.severity] || VALIDATION_SEVERITY_STYLE.info;
      const det = el('details', { style: 'font-size:0.8rem' });
      det.appendChild(el('summary', { style: `display:flex;align-items:baseline;gap:8px;cursor:pointer;list-style:none;padding:1px 0` },
        el('span', { style: `color:${style.color};font-weight:700;flex-shrink:0` }, style.icon),
        el('span', { style: 'color:var(--text)' }, f.message)
      ));
      const detailText = [f.explanation, f.detail].filter(Boolean).join(' — ');
      if (detailText) {
        det.appendChild(el('div', { style: 'margin:4px 0 4px 18px;font-size:0.72rem;color:var(--text-muted);background:rgba(148,163,184,0.08);padding:6px 8px;border-radius:4px;border-left:2px solid var(--border);white-space:pre-wrap;word-break:break-word' }, detailText));
      }
      list.appendChild(det);
    });
    return list;
  }

  function renderValidationCard(msg) {
    const findings  = msg.data?.findings || [];
    const errCount  = findings.filter(f => f.severity === 'error').length;
    const warnCount = findings.filter(f => f.severity === 'warning').length;
    const statusColor = errCount ? 'var(--danger)' : warnCount ? '#f59e0b' : '#22c55e';
    const statusText  = !findings.length ? 'No issues found ✓'
      : `${errCount} error${errCount !== 1 ? 's' : ''} · ${warnCount} warning${warnCount !== 1 ? 's' : ''}`;

    const card = el('div', { class: 'ai-msg ai-msg-ai', style: 'padding:0;overflow:hidden' },
      el('div', { style: 'padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)' },
        el('span', { style: 'font-weight:600;font-size:0.875rem' }, '⚑ Validation'),
        el('span', { style: `color:${statusColor};font-size:0.8rem;font-weight:600` }, statusText)
      )
    );
    if (findings.length) {
      card.appendChild(el('div', { style: 'padding:10px 14px' }, renderValidationExplainer(), renderValidationList(findings)));
    }
    return card;
  }

  function renderMessage(msg) {
    if (msg.role === 'user') {
      return el('div', { class: 'ai-msg ai-msg-user' }, msg.content);
    }
    if (msg.type === 'thinking') {
      const thinkingLabel = el('span', { class: 'ai-thinking-label' });
      const bubble = el('div', { class: 'ai-msg ai-msg-ai ai-msg-thinking' },
        el('div', { class: 'loader-ring' }),
        thinkingLabel
      );
      startThinkingStages(thinkingLabel, msg.stageMode || 'default');
      return bubble;
    }
    if (msg.type === 'progress') return renderProgressCard(msg);
    if (msg.type === 'intent') return renderIntentSummaryCard(msg);
    if (msg.type === 'edit-intent') return el('span', {});
    if (msg.type === 'recover') return renderRecoveryCard(msg);
    if (msg.type === 'plan') return renderPlanCard(msg);
    if (msg.type === 'result') return renderResultCard(msg);
    if (msg.type === 'diagnosis') return renderDiagnosisCard(msg);
    if (msg.type === 'edit-review') return renderEditReviewInlineCard(msg);
    if (msg.type === 'error') {
      const div = el('div', { class: 'ai-msg ai-msg-ai ai-msg-error' },
        el('div', {}, '✗ ' + msg.content)
      );
      const actions = el('div', { class: 'ai-error-actions' });
      if (msg.retryType === 'plan' && msg.retryBody) {
        const hasIntent = !!(msg.retryBody.intent);
        const retryLabel = hasIntent ? '↺ Continue build' : '↺ Retry';
        if (hasIntent) {
          div.appendChild(el('div', { class: 'ai-error-checkpoint' }, '✓ Intent saved — continuing will skip straight to plan generation'));
        }
        actions.appendChild(el('button', { class: 'btn btn-ai btn-sm', onClick: async () => {
          const sess = currentSession();
          if (sess) { sess.messages = sess.messages.filter(m => m.id !== msg.id); saveSessions(); }
          await proceedWithPlan(msg.retryBody);
        }}, retryLabel));
      }
      if (msg.retryType === 'apply' && msg.retryBody) {
        actions.appendChild(el('button', { class: 'btn btn-ai btn-sm', onClick: async () => {
          const sess = currentSession();
          if (sess) { sess.messages = sess.messages.filter(m => m.id !== msg.id); saveSessions(); }
          await applyPlan(msg.retryBody.plan, msg.retryBody.mode, null);
        }}, '↺ Retry apply'));
      }
      actions.appendChild(el('button', { class: 'btn btn-secondary btn-sm', onClick: () => {
        const sess = currentSession();
        if (sess) { sess.messages = sess.messages.filter(m => m.id !== msg.id); saveSessions(); renderMessages(); }
      }}, 'Dismiss'));
      div.appendChild(actions);
      return div;
    }
    if (msg.type === 'trace') return renderTraceCard(msg);
    if (msg.type === 'test')  return renderTestCard(msg);
    if (msg.type === 'validation') return renderValidationCard(msg);
    const bubble = el('div', { class: 'ai-msg ai-msg-ai' }, msg.content);
    const tokenEl = renderTokenUsage(msg.usage);
    if (tokenEl) bubble.appendChild(tokenEl);
    return bubble;
  }

  function renderPlanCard(msg) {
    const { plan, summary, mode } = msg.data;
    const lines = [];

    if (mode === 'build') {
      const nameSpan = el('span', { class: 'ai-plan-project-name' }, plan.project_name || summary.project_name || '');
      nameSpan.addEventListener('click', () => {
        const inp = document.createElement('input');
        inp.className = 'ai-plan-name-input';
        inp.value = plan.project_name || '';
        const commit = () => {
          const v = inp.value.trim();
          if (v) {
            plan.project_name = v;
            plan.subdomain = v.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            summary.project_name = v;
            saveSessions();
          }
          nameSpan.textContent = plan.project_name || summary.project_name || '';
          inp.replaceWith(nameSpan);
        };
        inp.addEventListener('blur', commit);
        inp.addEventListener('keydown', e => {
          if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
          if (e.key === 'Escape') { inp.value = plan.project_name || ''; inp.blur(); }
        });
        nameSpan.replaceWith(inp);
        inp.focus();
        inp.select();
      });
      lines.push(el('div', { class: 'ai-plan-row' }, el('strong', {}, 'Project: '), nameSpan));
      if (summary.tables && summary.tables.length) {
        lines.push(el('div', { class: 'ai-plan-section' }, 'Tables'));
        summary.tables.forEach(t => lines.push(el('div', { class: 'ai-plan-item' }, '+ ' + t)));
      }
      if (summary.frontend_files) {
        lines.push(el('div', { class: 'ai-plan-row', style: 'margin-top:6px' },
          el('strong', {}, 'Frontend: '), summary.frontend_files + ' files'
        ));
      }

      // Collapsible schema detail
      if (plan && plan.tables && plan.tables.length) {
        const schemaDetails = el('details', { class: 'ai-plan-details' },
          el('summary', { class: 'ai-plan-details-summary' }, 'Schema'),
          ...plan.tables.map(t =>
            el('div', { class: 'ai-plan-details-table' },
              el('div', { class: 'ai-plan-details-tname' }, t.name),
              ...(t.columns || []).map(c =>
                el('div', { class: 'ai-plan-details-col' },
                  el('span', { class: 'ai-plan-details-colname' }, c.name),
                  el('span', { class: 'ai-plan-details-coltype' }, c.type),
                  el('span', { class: 'ai-plan-details-colnull' + (c.nullable ? ' muted' : '') },
                    c.nullable ? 'NULL' : 'NOT NULL'
                  )
                )
              )
            )
          )
        );
        lines.push(schemaDetails);
      }

      // Collapsible file list
      if (plan && plan.frontend && plan.frontend.files && plan.frontend.files.length) {
        const filesDetails = el('details', { class: 'ai-plan-details' },
          el('summary', { class: 'ai-plan-details-summary' }, 'Files'),
          ...plan.frontend.files.map(f =>
            el('div', { class: 'ai-plan-details-file' }, f.path)
          )
        );
        lines.push(filesDetails);
      }

      // Validation — checked before you decide whether to build this at all.
      if (msg.data.validation && msg.data.validation.length) {
        const errCount = msg.data.validation.filter(f => f.severity === 'error').length;
        const validationDetails = el('details', { class: 'ai-plan-details', open: errCount > 0 },
          el('summary', { class: 'ai-plan-details-summary' },
            `⚑ Validation (${msg.data.validation.length})`),
          renderValidationExplainer(),
          renderValidationList(msg.data.validation)
        );
        lines.push(validationDetails);
      }

      // Download plan JSON button
      if (plan) {
        const dlBtn = el('button', { class: 'ai-plan-dl-btn', onClick: () => {
          const name = (plan.subdomain || plan.project_name || 'plan').replace(/\s+/g, '-').toLowerCase();
          downloadText(name + '.json', JSON.stringify(plan, null, 2));
        }}, '↓ Download JSON');
        lines.push(dlBtn);
      }
    } else if (mode === 'edit') {
      const hasChanges = (summary.add_tables && summary.add_tables.length) ||
                         (summary.add_columns && summary.add_columns.length) ||
                         (summary.update_policies && summary.update_policies.length) ||
                         summary.frontend_files;
      if (!hasChanges) {
        lines.push(el('div', { class: 'text-muted', style: 'font-size:13px' }, 'No changes needed for this request.'));
      } else {
        if (summary.add_tables && summary.add_tables.length) {
          lines.push(el('div', { class: 'ai-plan-section' }, 'New tables'));
          summary.add_tables.forEach(t => lines.push(el('div', { class: 'ai-plan-item' }, '+ ' + t)));
        }
        if (summary.add_columns && summary.add_columns.length) {
          lines.push(el('div', { class: 'ai-plan-section' }, 'New columns'));
          summary.add_columns.forEach(c => lines.push(el('div', { class: 'ai-plan-item' }, '+ ' + c)));
        }
        if (summary.update_policies && summary.update_policies.length) {
          lines.push(el('div', { class: 'ai-plan-section' }, 'Policy changes'));
          summary.update_policies.forEach(p => lines.push(el('div', { class: 'ai-plan-item' }, '~ ' + p)));
        }
        if (summary.frontend_files) {
          lines.push(el('div', { class: 'ai-plan-row', style: 'margin-top:6px' },
            el('strong', {}, 'Frontend: '), summary.frontend_files + ' files updated'
          ));
        }
        // Collapsible file list
        if (plan && plan.frontend && plan.frontend.files && plan.frontend.files.length) {
          const filesDetails = el('details', { class: 'ai-plan-details' },
            el('summary', { class: 'ai-plan-details-summary' }, 'Files'),
            ...plan.frontend.files.map(f =>
              el('div', { class: 'ai-plan-details-file' }, f.path)
            )
          );
          lines.push(filesDetails);
        }
        // Download plan JSON button
        if (plan && plan.frontend && plan.frontend.files && plan.frontend.files.length) {
          const dlBtn = el('button', { class: 'ai-plan-dl-btn', onClick: () => {
            const name = 'edit-' + (plan.project_id || 'plan');
            downloadText(name + '.json', JSON.stringify(plan, null, 2));
          }}, '↓ Download JSON');
          lines.push(dlBtn);
        }
      }
    }

    const tokenEl = renderTokenUsage(msg.data?.usage || msg.usage);
    const card = el('div', { class: 'ai-msg ai-msg-ai ai-plan-card' + (msg.settled ? ' ai-plan-settled' : '') },
      el('div', { class: 'ai-plan-title' }, "Here's my plan:"),
      ...lines,
      ...(tokenEl ? [tokenEl] : [])
    );

    if (!msg.settled) {
      const actionsDiv = el('div', { class: 'ai-plan-actions' });

      const cancelBtn = el('button', { class: 'btn btn-secondary btn-sm', onClick: () => {
        msg.settled = true;
        msg.cancelled = true;
        saveSessions();
        card.classList.add('ai-plan-settled');
        renderMessages();
      }}, 'Cancel');

      const applyBtn = el('button', { class: 'btn btn-ai btn-sm', onClick: async () => {
        delete msg.applyError;
        msg.settled = true;
        saveSessions();
        actionsDiv.innerHTML = '';
        actionsDiv.appendChild(el('span', { class: 'text-muted', style: 'font-size:12px' }, '⏳ Applying…'));
        card.classList.add('ai-plan-settled');
        await applyPlan(plan, mode, msg);
      }}, mode === 'build' ? '✓ Deploy to Staging' : '✓ Apply');

      if (msg.applyError) {
        actionsDiv.appendChild(el('div', { class: 'ai-plan-error-notice' }, '✕ ' + msg.applyError));
      }
      actionsDiv.appendChild(cancelBtn);
      actionsDiv.appendChild(applyBtn);
      card.appendChild(actionsDiv);
    } else if (msg.cancelled) {
      card.appendChild(el('div', { class: 'ai-plan-actions' },
        el('span', { class: 'text-muted', style: 'font-size:12px' }, 'Cancelled')
      ));
    } else {
      card.appendChild(el('div', { class: 'ai-plan-actions' },
        el('span', { style: 'font-size:12px;color:var(--accent)' }, '✓ Applied')
      ));
    }

    return card;
  }

  function renderResultCard(msg) {
    const data = msg.data;
    const lines = [];
    if (data.project) lines.push(el('div', { class: 'ai-result-row' }, '✓ Project: ', el('strong', {}, data.project.name)));
    if (data.tables && data.tables.length) lines.push(el('div', { class: 'ai-result-row' }, '✓ Tables: ' + data.tables.map(t => t.name || t).join(', ')));
    if (data.added_tables && data.added_tables.length) lines.push(el('div', { class: 'ai-result-row' }, '✓ Tables added: ' + data.added_tables.join(', ')));
    if (data.added_columns && data.added_columns.length) lines.push(el('div', { class: 'ai-result-row' }, '✓ Columns: ' + data.added_columns.join(', ')));
    if (data.site && !data.staging) lines.push(el('div', { class: 'ai-result-row' }, '✓ Frontend deployed'));
    if (data.staging) lines.push(el('div', { class: 'ai-result-row' }, '✓ Deployed to staging (preview)'));

    const card = el('div', { class: 'ai-msg ai-msg-ai ai-result-card' }, ...lines);

    const actions = el('div', { style: 'margin-top:10px;display:flex;gap:8px;flex-wrap:wrap' });

    if (data.project) {
      actions.appendChild(el('button', {
        class: 'btn btn-primary btn-sm',
        onClick: () => { close(); Router.navigate('/projects/' + data.project.id); }
      }, 'Open Project →'));
    }

    // View Site button — shown only when the build deployed straight to a
    // live site (no staging block); builds now always stage first, so this
    // only applies to any future/legacy live-direct path.
    if (data.site && data.site.id && !data.staging) {
      const siteUrl = `/sites/s${data.site.id}/current/`;
      actions.appendChild(el('a', {
        class: 'btn btn-secondary btn-sm',
        href: siteUrl,
        target: '_blank',
        rel: 'noopener'
      }, 'View Site →'));
    }

    // Edit staged to preview — offer View Staging + Publish to Live.
    if (data.staging && data.staging.deploy_id) {
      actions.appendChild(el('a', {
        class: 'btn btn-secondary btn-sm',
        href: data.staging.staging_url,
        target: '_blank',
        rel: 'noopener'
      }, 'View Staging →'));

      const publishBtn = el('button', { class: 'btn btn-ai btn-sm' }, '🚀 Publish to Live');
      publishBtn.addEventListener('click', async () => {
        publishBtn.disabled = true;
        const orig = publishBtn.textContent;
        // Publish gate: warn (don't block) when the latest test run failed.
        const warn = await latestTestWarning(data.staging.project_id);
        if (warn && !confirm('⚠ ' + warn + '.\n\nPublish to live anyway?')) {
          publishBtn.disabled = false;
          return;
        }
        publishBtn.textContent = '⏳ Publishing…';
        try {
          const { project_id, site_id, deploy_id } = data.staging;
          await Api.post(`/v1/projects/${project_id}/sites/${site_id}/deploys/${deploy_id}/publish`, {});
          data.staging.published = true;
          saveSessions();
          publishBtn.replaceWith(el('a', {
            class: 'btn btn-secondary btn-sm',
            href: `/sites/s${site_id}/current/`, target: '_blank', rel: 'noopener'
          }, '✓ Live — View Site →'));
        } catch (err) {
          publishBtn.disabled = false;
          publishBtn.textContent = orig;
          showToast('Publish failed: ' + (err.message || 'try again'));
        }
      });
      if (data.staging.published) {
        actions.appendChild(el('a', { class: 'btn btn-secondary btn-sm', href: `/sites/s${data.staging.site_id}/current/`, target: '_blank', rel: 'noopener' }, '✓ Live — View Site →'));
      } else {
        actions.appendChild(publishBtn);
      }
    }

    // Seed App — testing already ran automatically as part of the build/edit
    // itself (see the stages card's 'test' row), so there's no separate
    // "Run Tests" button here; re-testing on demand lives in the input bar's
    // "Run Full Test" button instead, scoped to an existing project's session.
    const testProjectId = data.project?.id || data.staging?.project_id || selectedProjectId;
    if (testProjectId && (data.site || data.project || data.staging)) {
      const seedBtn = el('button', { class: 'btn btn-secondary btn-sm' }, '🌱 Seed App');
      seedBtn.addEventListener('click', () => runProjectSeed(testProjectId, seedBtn));
      actions.appendChild(seedBtn);
    }

    // Download JSON — schema + generated frontend files + test/validation
    // results all in one file, the Review-off equivalent of the old plan
    // card's "Download plan JSON" button (which only ever had the plan,
    // since that flow paused before testing ever ran).
    if (data.plan) {
      const dlBtn = el('button', { class: 'btn btn-secondary btn-sm' }, '⭳ Download JSON');
      dlBtn.addEventListener('click', () => {
        const name = (data.plan.project_name || data.project?.name || 'build').replace(/\s+/g, '-').toLowerCase();
        downloadJson(`${name}.json`, {
          project_name: data.plan.project_name,
          tables: data.plan.tables || [],
          frontend_files: data.plan.frontend?.files || [],
          test_results: data.test || null,
          validation: data.validation || [],
        });
      });
      actions.appendChild(dlBtn);
    }

    if (actions.children.length) card.appendChild(actions);
    return card;
  }

  function renderDiagnosisCard(msg) {
    const data = msg.data;
    const lines = [el('div', { class: 'ai-diagnosis-text' }, data.diagnosis)];
    if (data.suggestions && data.suggestions.length) {
      lines.push(el('div', { class: 'ai-plan-section', style: 'margin-top:10px' }, 'Suggestions'));
      data.suggestions.forEach((s, i) =>
        lines.push(el('div', { class: 'ai-plan-item' }, (i + 1) + '. ' + s))
      );
    }
    const tokenEl = renderTokenUsage(data?.usage || msg.usage);
    if (tokenEl) lines.push(tokenEl);
    return el('div', { class: 'ai-msg ai-msg-ai ai-diagnosis-card' }, ...lines);
  }

  // For an 'edit_agent' loop step, ai.response is the raw {"tool", "args"}
  // action the model returned — surface it as "read_file: features/notes/notes.js"
  // instead of a generic label, so scanning the trace shows what the agent did
  // on each turn without opening every entry.
  function agentStepSummary(response) {
    if (!response || typeof response !== 'object') return null;
    const tool = response.tool;
    if (!tool) return null;
    const args = response.args || {};
    const arg = tool === 'search_code' ? args.query
      : tool === 'read_file' || tool === 'write_file' || tool === 'syntax_check' ? args.path
      : null;
    return arg ? `${tool}: ${arg}` : tool;
  }

  function renderAiCallEntry(ai) {
    const STAGE_LABELS = {
      chat:            'Chat Response',
      schema_pass_1:   'Schema Pass 1',
      schema_retry:    '↩ Schema Retry (self-healing)',
      frontend_pass_2: 'Frontend Pass 2',
      edit_pass:       'Edit Pass',
      edit_retry:      '↩ Edit Retry (self-healing)',
      edit_agent:      'Agent Step',
      diagnose:        'Diagnose',
    };
    const agentSummary = ai.stage === 'edit_agent' ? agentStepSummary(ai.response) : null;
    const label    = agentSummary || STAGE_LABELS[ai.stage] || ai.stage;
    const msStr    = ai.ms ? `${(ai.ms / 1000).toFixed(1)}s` : '';
    const tok      = ai.tokens || {};
    const tokStr   = tok.total_tokens ? `${tok.total_tokens.toLocaleString()} tok` : '';
    const retryBadge = ai.retry
      ? el('span', { class: 'ai-trace-retry-badge' }, 'retry')
      : null;

    const children = [
      el('summary', { class: 'ai-trace-ai-summary' },
        el('span', { class: 'ai-trace-ai-stage' }, label),
        el('span', { class: 'ai-trace-meta' }, [msStr, tokStr].filter(Boolean).join('  ')),
        ...(retryBadge ? [retryBadge] : [])
      )
    ];

    if (ai.error) {
      children.push(el('div', { class: 'ai-trace-error-notice' }, 'Rejected: ' + ai.error));
    }

    if (ai.system) {
      children.push(el('details', { class: 'ai-trace-sub' },
        el('summary', {}, `System Prompt (${ai.system.length.toLocaleString()} chars)`),
        el('pre', { class: 'ai-trace-json ai-trace-prompt' }, ai.system)
      ));
    }

    if (ai.history && ai.history.length) {
      children.push(el('details', { class: 'ai-trace-sub' },
        el('summary', {}, `History (${ai.history.length} turn${ai.history.length !== 1 ? 's' : ''})`),
        el('pre', { class: 'ai-trace-json' }, JSON.stringify(ai.history, null, 2))
      ));
    }

    if (ai.user_msg) {
      const truncNote = ai.user_msg_truncated
        ? ` [showing 5 000 of ${(ai.user_msg_len || 0).toLocaleString()} chars]` : '';
      children.push(el('details', { class: 'ai-trace-sub' },
        el('summary', {}, `User Message${truncNote}`),
        el('pre', { class: 'ai-trace-json' }, ai.user_msg)
      ));
    }

    if (ai.response !== undefined) {
      children.push(el('details', { class: 'ai-trace-sub' },
        el('summary', {}, 'AI Response'),
        el('pre', { class: 'ai-trace-json' }, JSON.stringify(ai.response, null, 2))
      ));
    }

    if (tok.total_tokens) {
      children.push(el('div', { class: 'ai-trace-tokens' },
        `Tokens: ${(tok.prompt_tokens || 0).toLocaleString()} in + ${(tok.completion_tokens || 0).toLocaleString()} out = ${(tok.total_tokens || 0).toLocaleString()} total`
      ));
    }

    return el('details', { class: 'ai-trace-ai-entry' }, ...children);
  }

  // ── Live build progress card ───────────────────────────────────────────────
  const BUILD_PROGRESS_STAGES = [
    { key: 'schema',   label: 'Designing database schema' },
    { key: 'design',   label: 'Choosing a visual design' },
    { key: 'frontend', label: 'Generating frontend code' },
  ];

  // Review-off ("watch only") build: one continuous progress card spanning
  // everything from understanding the request through a live, tested app —
  // seven stages so nothing (design brief, deploy, tests) is left invisible.
  const BUILD_WATCH_ONLY_STAGES = [
    { key: 'requirements', label: 'Understanding your requirements' },
    { key: 'schema',       label: 'Designing database schema' },
    { key: 'design',       label: 'Choosing a visual design' },
    { key: 'frontend',     label: 'Generating frontend code' },
    { key: 'validate',     label: 'Checking for mismatches' },
    { key: 'deploy',       label: 'Deploying to staging' },
    { key: 'test',         label: 'Running tests' },
  ];

  // Review-on build splits the above into two confirmable stages: schema+design
  // first (paused for user confirmation), then frontend on its own.
  const BUILD_SCHEMA_STAGES = [
    { key: 'schema', label: 'Designing database schema' },
    { key: 'design', label: 'Choosing a visual design' },
  ];
  const BUILD_FRONTEND_STAGES = [
    { key: 'frontend', label: 'Generating frontend code' },
    { key: 'validate', label: 'Checking for mismatches' },
  ];

  const EDIT_PROGRESS_STAGES = [
    { key: 'read',    label: 'Reading current schema & files' },
    { key: 'changes', label: 'Generating changes' },
  ];

  const TEST_PROGRESS_STAGES = [
    { key: 'script',   label: 'Preparing test script' },
    { key: 'stories',  label: 'Writing user-story tests' },
    { key: 'run',      label: 'Running browser tests' },
    { key: 'validate', label: 'Checking for mismatches' },
  ];

  function makeProgressMsg(stages, title, mode) {
    const list = stages || BUILD_PROGRESS_STAGES;
    return {
      id: 'progress_' + Date.now(),
      role: 'ai',
      type: 'progress',
      data: {
        title: title || 'Building your app',
        mode: mode || 'build',
        stages: list.map((s, i) => ({ ...s, status: i === 0 ? 'active' : 'pending', detail: '' })),
        error: null,
      },
    };
  }

  function progressSetStage(msg, key, status, detail) {
    const stages = msg.data.stages;
    const idx = stages.findIndex(s => s.key === key);
    if (idx === -1) return;
    // When a stage starts or finishes, anything before it is implicitly done.
    if (status === 'active' || status === 'done') {
      for (let i = 0; i < idx; i++) if (stages[i].status !== 'error') stages[i].status = 'done';
    }
    stages[idx].status = status;
    if (detail != null && detail !== '') stages[idx].detail = detail;
  }

  // Maps a progress card's stage keys to the aiTrace 'stage' values the
  // backend tags its AI calls with — lets each stage row show its own raw
  // system prompt/response/tokens once the job finishes, instead of only
  // being visible in the separate, undifferentiated trace card.
  const STAGE_TRACE_KEYS = {
    requirements: ['intent'],
    schema:       ['schema_pass_1', 'schema_retry'],
    design:       ['design_brief'],
    frontend:     ['frontend_pass_2'],
    changes:      ['edit_pass', 'edit_retry', 'edit_agent'],
  };
  function attachTraceToStages(progressMsg, aiTrace) {
    if (!aiTrace || !aiTrace.length) return;
    for (const stage of progressMsg.data.stages) {
      const keys = STAGE_TRACE_KEYS[stage.key];
      if (!keys) continue;
      const entries = aiTrace.filter(t => keys.includes(t.stage));
      if (entries.length) stage.traceEntries = entries;
    }
  }

  // Map a streamed build event onto the progress card's stage list.
  function applyProgressEvent(msg, ev) {
    if (!ev || !ev.stage) return;
    if (ev.stage === 'error') {
      const active = msg.data.stages.find(s => s.status === 'active');
      if (active) active.status = 'error';
      msg.data.error = ev.message || 'Something went wrong';
      return;
    }
    if (ev.stage === 'complete') {
      msg.data.stages.forEach(s => { if (s.status !== 'error') s.status = 'done'; });
      return;
    }
    const status = ev.status === 'start' ? 'active' : ev.status === 'done' ? 'done' : 'active';
    progressSetStage(msg, ev.stage, status, ev.detail || '');
  }

  function renderProgressCard(msg) {
    const data = msg.data || { stages: [] };
    const ICON = { pending: '○', active: '', done: '✓', error: '✕' };
    const rows = (data.stages || []).map(s => {
      const icon = s.status === 'active'
        ? el('span', { class: 'ai-progress-spin' })
        : el('span', { class: 'ai-progress-icon ai-progress-icon--' + s.status }, ICON[s.status] || '○');
      // Once a stage has something to show (detail text, the matching raw AI
      // call trace, or — for the 'test' stage — the full test+validation
      // breakdown), its row becomes a click-to-expand disclosure instead of a
      // static line. Expand state is stored on the stage object itself (not
      // just left as DOM state) so it survives the frequent re-renders that
      // happen while later stages are still progressing — otherwise every
      // renderMessages() call would rebuild fresh <details> elements and
      // silently re-collapse whatever the user had opened.
      const hasExpandable = s.detail || (s.traceEntries && s.traceEntries.length) || s.testData || s.rawData;
      if (hasExpandable) {
        const summary = el('summary', { class: 'ai-progress-summary' }, icon, el('span', { class: 'ai-progress-label ai-progress-label--clickable' }, s.label));
        const body = el('div', { class: 'ai-progress-detail-body' });
        if (s.detail) body.appendChild(el('div', { class: 'ai-progress-detail' }, s.detail));
        if (s.traceEntries && s.traceEntries.length) {
          body.appendChild(el('div', { class: 'ai-progress-trace' }, ...s.traceEntries.map(renderAiCallEntry)));
        }
        if (s.testData) {
          body.appendChild(renderTestCard({ id: (msg.id || 'progress') + '_test', data: s.testData }));
        }
        if (s.rawData) {
          body.appendChild(el('details', { class: 'ai-trace-sub' },
            el('summary', {}, 'Raw deploy result'),
            el('pre', { class: 'ai-trace-json' }, JSON.stringify(s.rawData, null, 2))
          ));
        }
        const details = el('details', { class: 'ai-progress-row ai-progress-row--' + s.status }, summary, body);
        details.open = !!s.expanded;
        details.addEventListener('toggle', () => { s.expanded = details.open; });
        return details;
      }
      const textWrap = el('div', { class: 'ai-progress-text' },
        el('div', { class: 'ai-progress-label' }, s.label)
      );
      return el('div', { class: 'ai-progress-row ai-progress-row--' + s.status }, icon, textWrap);
    });
    const card = el('div', { class: 'ai-msg ai-msg-ai ai-progress-card' },
      el('div', { class: 'ai-progress-title' }, data.title || 'Building your app'),
      ...rows
    );
    if (data.error) {
      card.appendChild(el('div', { class: 'ai-progress-error' }, '✕ ' + data.error));
    }
    return card;
  }

  function renderTraceCard(msg) {
    const rows = (msg.data || []).map(entry => {
      const ok = entry.status === 200;
      const rowChildren = [
        el('summary', { class: 'ai-trace-summary' },
          el('span', { class: 'ai-trace-call' }, entry.call),
          el('span', { class: 'ai-trace-meta' }, `${(entry.ms / 1000).toFixed(1)}s  ${ok ? '✓' : '✕'} ${entry.status}`)
        ),
        el('details', { class: 'ai-trace-sub' },
          el('summary', {}, 'Request'),
          el('pre', { class: 'ai-trace-json' }, JSON.stringify(entry.inputs, null, 2))
        ),
        el('details', { class: 'ai-trace-sub' },
          el('summary', {}, 'Response'),
          el('pre', { class: 'ai-trace-json' }, JSON.stringify(entry.outputs, null, 2))
        ),
      ];

      // AI-internal call breakdown (schema pass, frontend pass, retries, etc.)
      const aiCalls = entry.outputs?.aiTrace;
      if (aiCalls && aiCalls.length) {
        rowChildren.push(
          el('div', { class: 'ai-trace-ai-section' },
            el('div', { class: 'ai-trace-ai-header' }, `AI Calls (${aiCalls.length})`),
            ...aiCalls.map(renderAiCallEntry)
          )
        );
      }

      return el('details', { class: 'ai-trace-row' }, ...rowChildren);
    });
    const topAttrs = { class: 'ai-msg ai-msg-ai ai-trace-card' };
    if (msg.live) topAttrs.open = true;
    const liveDot = msg.live ? el('span', { class: 'ai-trace-live-dot' }) : null;

    const dlBtn = el('button', { class: 'ai-trace-dl-btn', title: 'Download trace as JSON', onClick: (e) => {
      e.preventDefault(); e.stopPropagation();
      downloadText('trace-' + new Date().toISOString().slice(0,19).replace(/[:T]/g,'-') + '.json',
        JSON.stringify(msg.data || [], null, 2));
    }}, '⬇');

    return el('details', topAttrs,
      el('summary', { class: 'ai-trace-top-summary' }, ...(liveDot ? [liveDot] : []), 'Session trace', dlBtn),
      ...rows
    );
  }

  // Despite the name, this no longer renders a picker — it just reflects the
  // fixed project the panel was opened for (read-only; see projectLabel above).
  function renderProjectPicker() {
    if (!panelEl) return;
    const label = panelEl.querySelector('.ai-project-label');
    if (label) {
      const proj = projects.find(p => String(p.id) === String(selectedProjectId));
      label.textContent = proj ? proj.name : (selectedProjectId ? '' : '✦ New project');
    }
    const testBtn = panelEl.querySelector('.ai-run-tests-btn');
    if (testBtn) testBtn.style.display = selectedProjectId ? '' : 'none';
  }

  async function loadSessionMessages(id) {
    if (!id || String(id).startsWith('tmp_')) return; // unsaved local session
    try {
      const full = await Api.get('/v1/ai/sessions/' + id);
      if (full && Array.isArray(full.messages)) {
        // Tolerant comparison — id can arrive as a string (e.g. from
        // localStorage on a fresh page load) while session ids from the API
        // are numbers, which a strict === would silently miss.
        const idx = sessions.findIndex(s => String(s.id) === String(id));
        if (idx !== -1) sessions[idx].messages = full.messages;
      }
    } catch(e) {}
  }

  async function switchSession(id) {
    currentSessionId = id;
    const sess = getSession(id);
    if (sess) selectedProjectId = sess.projectId;
    localStorage.setItem(aiSidKey(), id);
    renderSidebar();
    renderMessages();
    renderProjectPicker();
    await loadSessionMessages(id);
    resumeActiveJobIfAny(getSession(id));
    renderMessages();
  }

  async function newSession() {
    const sess = await createSession(selectedProjectId);
    currentSessionId = sess.id;
    localStorage.setItem(aiSidKey(), sess.id);
    renderSidebar();
    renderMessages();
  }

  function toggleSidebar(force) {
    sidebarVisible = force !== undefined ? force : !sidebarVisible;
    if (!panelEl) return;
    const sidebar = panelEl.querySelector('.ai-sessions');
    const overlay = panelEl.querySelector('.ai-sidebar-overlay');
    if (sidebar) sidebar.classList.toggle('ai-sidebar-visible', sidebarVisible);
    if (overlay) overlay.classList.toggle('ai-sidebar-overlay-visible', sidebarVisible);
  }

  async function sendMessage() {
    if (!panelEl) return;
    const textarea = panelEl.querySelector('#ai-textarea');
    const prompt = textarea ? textarea.value.trim() : '';
    if (!prompt) return;
    if (textarea) { textarea.value = ''; textarea.style.height = 'auto'; const sb = panelEl.querySelector('.ai-send-btn'); if (sb) sb.disabled = true; }

    if (!currentSessionId || !getSession(currentSessionId)) {
      const sess = await createSession(selectedProjectId);
      currentSessionId = sess.id;
    }

    await addMessage(currentSessionId, { role: 'user', content: prompt });
    renderSidebar();
    renderMessages();

    const sess = currentSession();

    // Build conversation history for Gemini context. Validation and testing
    // are now unconditional stages of every build/edit, not a toggle.
    const body = { prompt, validate: true };
    if (selectedProjectId) body.project_id = selectedProjectId;
    const priorMessages = (sess ? sess.messages : []).filter(m => m.type !== 'thinking');
    if (priorMessages.length > 0) {
      body.history = priorMessages.slice(-20).map(m => {
        if (m.role === 'user') return { role: 'user', text: m.content };
        if (m.type === 'plan') {
          const s = m.data?.summary || {};
          return { role: 'model', text: 'I proposed a plan. Summary: ' + JSON.stringify(s) };
        }
        if (m.type === 'result') return { role: 'model', text: 'The changes were applied successfully.' };
        if (m.type === 'diagnosis') return { role: 'model', text: 'Diagnosis: ' + (m.data?.diagnosis || '') + (m.data?.suggestions?.length ? ' Suggestions: ' + m.data.suggestions.join('; ') : '') };
        if (m.type === 'intent') {
          const ni = normalizeIntent(m.data || {});
          const an = (ni.actors || []).map(a => (typeof a === 'string' ? a : (a && a.name) || '')).filter(Boolean);
          const st = [];
          (ni.actors || []).forEach(a => ((a && Array.isArray(a.stories)) ? a.stories : []).forEach(s => st.push(typeof s === 'string' ? s : (s && s.title) || '')));
          return { role: 'model', text: 'Intent confirmed — actors: [' + an.join(', ') + '], stories: [' + st.filter(Boolean).join('; ') + ']' };
        }
        if (m.type === 'edit-intent') return { role: 'model', text: 'Edit changes confirmed: ' + (m.data?.confirmed || []).map(s => s.label).join('; ') };
        if (m.type === 'recover') return { role: 'model', text: 'Build failed and recovery was offered: ' + (m.data?.diagnosis || '') };
        if (m.type === 'error') return { role: 'model', text: 'Error: ' + m.content };
        return { role: 'model', text: m.content || '' };
      }).filter(h => h.text.trim() !== '');
    }

    // ── Chat mode: route everything through the conversational plan endpoint ──
    if (!buildMode) {
      body.chatMode = true;  // tells the backend to skip build-intent detection
      await proceedWithPlan(body);
      return;
    }

    // ── Build mode, Review ON: intent/suggestions review cards ──────────────
    if (reviewEnabled) {
      const thinkingId = 'review_' + Date.now();
      currentAbortController = new AbortController();
      operationInProgress = true;
      updateSendBtn();
      if (!selectedProjectId) {
        if (sess) sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode: 'intent' });
        renderMessages();
        try {
          const res = await callWithFallback('/v1/ai/build', { review: true, prompt, history: body.history || [] }, currentAbortController.signal);
          stopThinkingStages?.();
          if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
          renderMessages();
          if (res.mode === 'intent') showIntentReviewCard(res.intent, body);
        } catch(e) {
          stopThinkingStages?.();
          if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
          if (!(e instanceof DOMException && e.name === 'AbortError')) {
            await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Something went wrong: ${e.message}`, retryBody: body, retryType: 'plan' });
            renderSidebar(); renderMessages();
          }
        }
      } else {
        if (sess) sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode: 'edit' });
        renderMessages();
        try {
          const res = await callWithFallback('/v1/ai/plan', { mode: 'suggest', prompt, project_id: selectedProjectId, history: body.history || [] }, currentAbortController.signal);
          stopThinkingStages?.();
          if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
          renderMessages();
          showEditReviewCard(res.suggestions, body);
        } catch(e) {
          stopThinkingStages?.();
          if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
          if (!(e instanceof DOMException && e.name === 'AbortError')) {
            await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Something went wrong: ${e.message}`, retryBody: body, retryType: 'plan' });
            renderSidebar(); renderMessages();
          }
        }
      }
      currentAbortController = null;
      operationInProgress = false;
      updateSendBtn();
      return;
    }

    // ── Build mode, Review OFF: fast path — deploy confirm card ─────────────
    await proceedWithBuildDirect(body);
  }

  function buildApplySummary(result, mode) {
    const lines = [];
    if (mode === 'build') {
      if (result.project) lines.push(`**${result.project.name}** is ready.`);
      if (result.tables?.length) {
        const names = result.tables.map(t => `${t.name} (${t.columns} col${t.columns !== 1 ? 's' : ''})`).join(', ');
        lines.push(`Created ${result.tables.length} table${result.tables.length !== 1 ? 's' : ''}: ${names}.`);
      }
      if (result.staging)   lines.push('Deployed to staging — preview, then publish to live.');
      else if (result.site) lines.push('Site created — no frontend files were generated.');
    }
    if (mode === 'edit') {
      if (result.added_tables?.length)     lines.push(`Added ${result.added_tables.length} new table${result.added_tables.length !== 1 ? 's' : ''}: ${result.added_tables.join(', ')}.`);
      if (result.added_columns?.length)    lines.push(`Added ${result.added_columns.length} column${result.added_columns.length !== 1 ? 's' : ''}.`);
      if (result.updated_policies?.length) lines.push(`Updated ${result.updated_policies.length} polic${result.updated_policies.length !== 1 ? 'ies' : 'y'}.`);
      if (result.seeded?.length)           lines.push(`Seeded ${result.seeded.join(', ')}.`);
      if (result.staging)                  lines.push('Changes deployed to staging — preview, then publish to live.');
      else if (result.deploy)              lines.push('Frontend redeployed.');
      if (!lines.length)                   lines.push('No changes were needed.');
    }
    return lines.length ? 'Done! ' + lines.join(' ') : 'Applied successfully.';
  }

  async function applyPlan(plan, mode, planMsg, validation) {
    const sess = currentSession();
    const thinkingId = 'apply_' + Date.now();
    let autoTestProjectId = null;
    if (sess) sess.messages.push({ id: thinkingId, role: 'ai', type: 'thinking', content: '', stageMode: mode });

    if (!liveTraceMsg) {
      liveTraceMsg = { id: 'trace_' + Date.now(), role: 'ai', type: 'trace', data: [], live: true };
      if (sess) sess.messages.push(liveTraceMsg);
    }
    operationInProgress = true;
    operationMode = mode;
    currentAbortController = new AbortController();
    updateSendBtn();
    renderMessages();

    const { provider: aProvider, model: aModel } = getSelectedModel();
    try {
      const t0 = Date.now();
      const result = await Api.post('/v1/ai/apply', { mode, plan, provider: aProvider, model: aModel }, currentAbortController.signal);
      liveTraceMsg.data.push({ call: 'POST /v1/ai/apply', inputs: { mode, provider: aProvider, model: aModel }, status: 200, outputs: result, ms: Date.now() - t0 });
      renderMessages();
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);

      await addMessage(currentSessionId, { role: 'ai', type: 'result', content: '', data: result });
      // Builds keep it to the result card alone (project/tables/deploy status
      // + action buttons) — a separate "Done! **X** is ready..." narration on
      // top of that read as redundant, overly technical noise. Edits still
      // get the summary since their result card alone doesn't say what changed.
      if (mode === 'edit') {
        await addMessage(currentSessionId, { role: 'ai', type: 'chat', content: buildApplySummary(result, mode) });
      }

      // Edits always auto-test. Builds only auto-test when Review is off
      // ("watch only" — everything runs straight through); with Review on,
      // testing is its own confirmable stage — the user clicks "Run Full Test"
      // in the input bar manually instead of it firing on its own.
      const hasDeployed = !!(result.deploy || result.staging || result.site);
      const willAutoTest = mode === 'edit' ? hasDeployed : (hasDeployed && !reviewEnabled);
      // Edits auto-apply (no separate review step), so validation shows here,
      // after the deploy — build mode already showed it in the plan card
      // before the user chose to apply at all. Skip this when auto-test is
      // about to fire anyway — its combined test+validate card will show the
      // same findings fresh moments later, and showing both would be redundant.
      if (mode === 'edit' && validation && !willAutoTest) {
        await addMessage(currentSessionId, { role: 'ai', type: 'validation', content: '', data: { findings: validation } });
      }

      // A build starts with no project (selectedProjectId is null) — once it
      // creates one, attach this session to it so it shows up in THAT
      // project's own history from now on instead of staying in the
      // unassigned "Build with AI" bucket.
      if (mode === 'build' && result.project?.id && !selectedProjectId) {
        const oldKey = aiSidKey();
        selectedProjectId = result.project.id;
        if (sess) sess.projectId = selectedProjectId;
        if (!projects.some(p => String(p.id) === String(selectedProjectId))) {
          projects.push(result.project);
        }
        localStorage.removeItem(oldKey);
        localStorage.setItem(aiSidKey(), currentSessionId);
        try { await Api.patch('/v1/ai/sessions/' + currentSessionId, { project_id: selectedProjectId }); } catch (_) {}
        renderProjectPicker();
      }

      // Auto-test: only when a frontend actually got deployed (there's nothing
      // to browser-test if the apply was schema-only).
      if (willAutoTest) {
        autoTestProjectId = result.project?.id || result.staging?.project_id
          || (mode === 'edit' ? plan.project_id : null);
      }
    } catch(e) {
      if (e instanceof DOMException && e.name === 'AbortError') {
        if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; }
        if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);
        operationInProgress = false; operationMode = null; currentAbortController = null;
        updateSendBtn(); renderMessages();
        return;
      }
      console.error('[AiPanel] /v1/ai/apply failed', { mode, status: e.status, error: e });
      liveTraceMsg.data.push({ call: 'POST /v1/ai/apply', inputs: { mode, provider: aProvider, model: aModel }, status: e.status || 0, outputs: { error: e.message }, ms: 0 });
      renderMessages();
      stopThinkingStages?.();
      if (sess) sess.messages = sess.messages.filter(m => m.id !== thinkingId);

      if (planMsg) {
        planMsg.settled = false;
        planMsg.applyError = e.message;
        saveSessions();
      } else {
        await addMessage(currentSessionId, { role: 'ai', type: 'error', content: `Something went wrong: ${e.message}`, retryBody: { plan, mode }, retryType: 'apply' });
      }
    }

    if (liveTraceMsg) { liveTraceMsg.live = false; liveTraceMsg = null; }
    operationInProgress = false;
    operationMode = null;
    currentAbortController = null;
    updateSendBtn();
    if (sess) await persistSession(sess);

    renderMessages();

    // Kick off the automatic post-deploy test run after the apply operation
    // has fully wound down (it manages its own progress card and job state).
    if (autoTestProjectId) runProjectTests(autoTestProjectId, null, true);
  }

  function buildPanel() {
    const panel = document.createElement('div');
    panel.className = 'ai-panel';
    panel.id = 'ai-panel';

    const sidebarOverlay = el('div', { class: 'ai-sidebar-overlay', onClick: () => toggleSidebar(false) });

    const sessionList = el('div', { class: 'ai-session-list' });
    const sidebar = el('div', { class: 'ai-sessions' },
      el('div', { class: 'ai-sessions-header' },
        el('span', { class: 'ai-sessions-title' }, 'History')
      ),
      sessionList
    );

    const messages = el('div', { class: 'ai-messages' });

    // Read-only — the panel is always scoped to whatever project it was opened
    // for (or no project, for a fresh "Build with AI"); it can never be
    // switched mid-conversation, so history stays per-project.
    const projectLabel = el('span', { class: 'ai-project-label' }, '');

    // Manual re-test on demand — only useful once there's an existing project
    // to test (a fresh build already auto-tests as part of its own pipeline),
    // so this only shows inside an existing project's AI session.
    const runTestsBtn = el('button', {
      class: 'btn btn-secondary btn-sm ai-run-tests-btn',
      style: selectedProjectId ? '' : 'display:none',
      title: 'Re-run the full browser test suite against this project',
      onClick: () => runProjectTests(selectedProjectId, runTestsBtn)
    }, '▶ Run Full Test');

    const textarea = el('textarea', {
      id: 'ai-textarea',
      class: 'ai-textarea',
      placeholder: 'Build, edit, or diagnose…'
    });
    textarea.setAttribute('rows', '1');
    textarea.addEventListener('input', () => {
      textarea.style.height = 'auto';
      textarea.style.height = Math.min(textarea.scrollHeight, 180) + 'px';
      if (!operationInProgress) sendBtn.disabled = textarea.value.trim() === '';
    });

    const sendBtn = el('button', { class: 'btn btn-ai ai-send-btn', onClick: () => {
      if (operationInProgress) { abortCurrentOperation(); } else { sendMessage(); }
    }}, '↑');
    sendBtn.disabled = true;
    sendBtnEl = sendBtn;

    const reviewToggle = el('button', {
      class: 'ai-review-toggle' + (reviewEnabled ? ' active' : ''),
      title: 'Review intent before building',
      onClick: () => {
        reviewEnabled = !reviewEnabled;
        localStorage.setItem('sb:ai_review', reviewEnabled ? '1' : '0');
        reviewToggle.classList.toggle('active', reviewEnabled);
      }
    }, 'Review');

    const modeBtn = el('button', {
      class: 'ai-mode-btn' + (buildMode ? ' active' : ''),
      title: buildMode ? 'Switch to Chat mode' : 'Switch to Build mode',
      onClick: () => {
        buildMode = !buildMode;
        localStorage.setItem('sb:ai_build', buildMode ? '1' : '0');
        modeBtn.className = 'ai-mode-btn' + (buildMode ? ' active' : '');
        modeBtn.title = buildMode ? 'Switch to Chat mode' : 'Switch to Build mode';
        modeBtn.textContent = buildMode ? '🔨 Build' : '💬 Chat';
        reviewToggle.style.display = buildMode ? '' : 'none';
      }
    }, buildMode ? '🔨 Build' : '💬 Chat');

    // Hide review toggle when starting in chat mode
    if (!buildMode) reviewToggle.style.display = 'none';

    // Model selector button + dropdown — lives in the bottom input bar with
    // the other toggles, opening upward since it sits at the bottom.
    function buildModelSelector() {
      const sel = getSelectedModel();
      const btn = el('button', { class: 'ai-model-btn', title: 'Switch AI model' }, sel.label + ' ▾');
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const existing = inputBar.querySelector('.ai-model-dropdown');
        if (existing) { existing.remove(); return; }
        const dropdown = el('div', { class: 'ai-model-dropdown' });
        AI_MODELS.forEach(m => {
          const cur = getSelectedModel();
          const item = el('div', { class: 'ai-model-option' + (m.model === cur.model ? ' active' : '') },
            el('span', {}, m.label),
            el('span', { class: 'ai-model-badge' }, m.badge)
          );
          item.addEventListener('click', () => {
            setSelectedModel(m);
            btn.textContent = m.label + ' ▾';
            dropdown.remove();
          });
          dropdown.appendChild(item);
        });
        inputBar.appendChild(dropdown);
        const onOutside = () => { dropdown.remove(); document.removeEventListener('click', onOutside); };
        setTimeout(() => document.addEventListener('click', onOutside), 0);
      });
      return btn;
    }
    const modelSelectorBtn = buildModelSelector();

    const inputBar = el('div', { class: 'ai-input-bar' },
      el('div', { class: 'ai-input-card' },
        textarea,
        el('div', { class: 'ai-input-actions' },
          runTestsBtn,
          modelSelectorBtn,
          modeBtn,
          reviewToggle,
          sendBtn
        )
      )
    );

    const hamburgerBtn = el('button', { class: 'ai-hamburger', onClick: () => toggleSidebar() }, '☰');
    const newSessionBtn = el('button', { class: 'ai-new-session-btn', title: 'New session', onClick: newSession }, '✎');
    const closeBtn = el('button', { class: 'ai-header-close', onClick: close }, '×');

    const header = el('div', { class: 'ai-header' },
      el('div', { class: 'ai-header-left' },
        hamburgerBtn,
        el('div', { class: 'ai-header-titles' },
          el('span', { class: 'ai-header-title' }, '✦ SupaBein AI'),
          projectLabel
        )
      ),
      newSessionBtn,
      closeBtn
    );

    const mainArea = el('div', { class: 'ai-main' }, header, messages, inputBar);

    panel.appendChild(sidebarOverlay);
    panel.appendChild(sidebar);
    panel.appendChild(mainArea);

    return panel;
  }

  async function open(options = {}) {
    if (isOpen) return;
    isOpen = true;

    if (!panelEl) {
      panelEl = buildPanel();
      document.body.appendChild(panelEl);
    }

    // Push a history entry so the phone/browser back button closes the panel
    // and returns to the page behind it instead of leaving the app entirely.
    history.pushState({ aiPanel: true }, '');
    historyPushed = true;

    // Show panel immediately so animation starts without waiting for data.
    // Deliberately NOT auto-focusing the textarea here — that pops the mobile
    // keyboard the instant the panel opens. Focus only when the user taps in.
    panelEl.classList.add('ai-panel-open');
    getOrCreateBackdrop().classList.add('active');

    const autoProject = detectCurrentProject();
    selectedProjectId = (options.projectId !== undefined) ? options.projectId : autoProject;

    // Sessions are loaded scoped to this exact project (or the unassigned
    // "Build with AI" bucket when there's none) — a different project's
    // history never leaks in, and the panel can't switch projects mid-chat.
    await Promise.all([loadSessions(selectedProjectId), loadProjects()]);
    renderProjectPicker();

    // Figure out which session to open. If the last-used session for THIS
    // project (this page load, or from localStorage after a reload) has a
    // build/edit job that never resolved, reopen THAT session instead of
    // starting fresh so the panel can reconnect to it — this is what survives
    // a reload/rotation, since it doesn't depend on the in-memory
    // operationInProgress flag. currentSessionId may be left over from a
    // different project's panel within the same page lifetime, so only trust
    // it if it's actually in this project's (already project-scoped) list.
    const lastId = (currentSessionId && getSession(currentSessionId)) ? currentSessionId : localStorage.getItem(aiSidKey());
    let resumeTarget = null;
    if (lastId && getSession(lastId)) {
      await loadSessionMessages(lastId);
      const candidate = getSession(lastId);
      if (candidate && candidate.messages.some(m => m.type === 'progress' && m.data.jobId && !m.data.jobDone)) {
        resumeTarget = candidate;
      }
    }

    if (resumeTarget) {
      currentSessionId = resumeTarget.id;
    } else if (operationInProgress && getSession(currentSessionId)) {
      // Panel was just closed and reopened within the same page lifetime — keep going.
    } else {
      // Always start fresh on open so the user isn't confused by a previous project's chat
      const sess = await createSession(selectedProjectId);
      currentSessionId = sess.id;
      localStorage.setItem(aiSidKey(), currentSessionId);
    }

    renderSidebar();
    renderMessages();
    renderProjectPicker();

    await loadSessionMessages(currentSessionId);
    resumeActiveJobIfAny(currentSession());

    if (operationInProgress && !resumeTarget) {
      const sess = currentSession();
      if (sess && !sess.messages.some(m => m.type === 'thinking')) {
        sess.messages.push({ id: 'thinking_reopen', role: 'ai', type: 'thinking', content: '', stageMode: operationMode || 'default' });
      }
      if (liveTraceMsg && sess && !sess.messages.some(m => m.id === liveTraceMsg.id)) {
        sess.messages.push(liveTraceMsg);
      }
    }
    renderMessages();
  }

  function close() {
    if (!panelEl) return;
    panelEl.classList.remove('ai-panel-open');
    if (backdropEl) backdropEl.classList.remove('active');
    isOpen = false;
    historyPushed = false;
    sidebarVisible = false;
    toggleSidebar(false);
  }

  // Let the phone/browser back button close the panel instead of leaving the
  // app — open() pushes a history entry for this exact reason.
  window.addEventListener('popstate', () => {
    if (isOpen) close();
  });

  function toggle(options) {
    if (isOpen) close(); else open(options);
  }

  // Open the panel and jump straight to a specific saved session (used by Home).
  async function openSession(id) {
    await open();
    if (id) { try { await switchSession(id); } catch (_) {} }
  }

  return { open, close, toggle, openSession };
})();

// Projects list
function homeTimeAgo(ts) {
  if (!ts) return '';
  const d = parseServerDate(ts);
  if (!d) return fmtDate(ts);
  const s = Math.floor((Date.now() - d.getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return Math.floor(s / 60) + 'm ago';
  if (s < 86400) return Math.floor(s / 3600) + 'h ago';
  if (s < 604800) return Math.floor(s / 86400) + 'd ago';
  return fmtDate(ts);
}

function renderHomeActivityItem(a) {
  const ICON = { project_created: '✦', deploy: '🚀', session: '💬' };
  let label, sub = a.project_name || '', href = null, onClick = null;
  if (a.type === 'project_created') {
    label = 'Created ' + (a.project_name || 'a project');
    href = '#/projects/' + a.project_id;
  } else if (a.type === 'deploy') {
    const t = a.target === 'live' ? 'live' : a.target === 'staging' ? 'staging' : 'archived';
    label = 'Deployed ' + (a.project_name || '');
    sub = t.charAt(0).toUpperCase() + t.slice(1) + (a.status && a.status !== 'ready' ? ' · ' + a.status : '');
    if (a.project_id) href = '#/projects/' + a.project_id + '/sites';
  } else if (a.type === 'session') {
    label = a.name && a.name !== 'New session' ? a.name : 'AI session';
    sub = a.project_name ? 'on ' + a.project_name : 'Platform';
    onClick = () => AiPanel.openSession(a.session_id);
  } else {
    label = a.type;
  }
  const row = el('div', { class: 'home-activity-item' + (href || onClick ? ' home-activity-clickable' : '') },
    el('span', { class: 'home-activity-icon' }, ICON[a.type] || '•'),
    el('div', { class: 'home-activity-text' },
      el('div', { class: 'home-activity-label' }, label),
      sub ? el('div', { class: 'home-activity-sub' }, sub) : null
    ),
    el('span', { class: 'home-activity-time' }, homeTimeAgo(a.ts))
  );
  if (href) row.addEventListener('click', () => { window.location.hash = href; });
  else if (onClick) row.addEventListener('click', onClick);
  return row;
}

async function renderHome() {
  if (!requireAuth()) return;
  const user = Auth.getUser();
  const name = user?.email ? user.email.split('@')[0] : 'there';

  renderLayout(null, 'home', [
    el('div', { class: 'home-hero' },
      el('div', { class: 'home-hero-text' },
        el('h1', { class: 'home-greeting' }, 'Welcome back, ' + name),
        el('p', { class: 'home-sub' }, 'Build, edit, and ship your backends.')
      ),
      el('div', { class: 'home-hero-actions' },
        el('button', { class: 'btn btn-ai', onClick: () => AiPanel.open() }, '✦ Build with AI'),
        el('button', { class: 'btn btn-secondary', onClick: () => showNewProjectModal() }, '+ New Project')
      )
    ),
    el('div', { id: 'home-body' }, el('div', { class: 'home-loading text-muted' }, 'Loading your overview…'))
  ]);

  let o;
  try {
    o = await Api.get('/v1/overview');
  } catch (e) {
    const body = document.getElementById('home-body');
    if (body) { body.innerHTML = ''; body.appendChild(el('div', { class: 'alert alert-error' }, 'Could not load your overview — ' + e.message)); }
    return;
  }

  const body = document.getElementById('home-body');
  if (!body) return;
  body.innerHTML = '';

  // Empty state — brand new account with no projects.
  if (!o.stats.projects) {
    body.appendChild(el('div', { class: 'home-empty' },
      el('div', { class: 'home-empty-icon' }, '✦'),
      el('p', {}, 'No projects yet — describe an app and let the AI build it.'),
      el('button', { class: 'btn btn-ai', style: 'margin-top:8px', onClick: () => AiPanel.open() }, '✦ Build with AI')
    ));
    return;
  }

  // Needs attention.
  if (o.needs_attention && o.needs_attention.length) {
    const strip = el('div', { class: 'home-attention' });
    o.needs_attention.forEach(n => {
      const a = el('a', { class: 'home-attention-item', href: `#/projects/${n.project_id}/sites/${n.site_id}` },
        el('span', { class: 'home-attention-dot' }),
        el('span', {}, el('strong', {}, n.project_name), ' has staging changes not published'),
        el('span', { class: 'home-attention-cta' }, 'Review & publish →')
      );
      strip.appendChild(a);
    });
    body.appendChild(strip);
  }

  // Stats.
  const stat = (n, label) => el('div', { class: 'home-stat' },
    el('div', { class: 'home-stat-num' }, String(n)),
    el('div', { class: 'home-stat-label' }, label)
  );
  body.appendChild(el('div', { class: 'home-stats' },
    stat(o.stats.projects, o.stats.projects === 1 ? 'Project' : 'Projects'),
    stat(o.stats.tables, o.stats.tables === 1 ? 'Table' : 'Tables'),
    stat(o.stats.live_sites, o.stats.live_sites === 1 ? 'Live site' : 'Live sites')
  ));

  // Recent projects.
  if (o.recent_projects && o.recent_projects.length) {
    body.appendChild(el('div', { class: 'home-section-head' },
      el('span', { class: 'sb-section-label' }, 'Recent projects'),
      el('a', { class: 'home-section-link', href: '#/projects' }, 'All projects →')
    ));
    const grid = el('div', { class: 'home-proj-grid' });
    o.recent_projects.forEach(p => {
      const links = el('div', { class: 'home-proj-links' });
      links.appendChild(el('a', { class: 'home-proj-link', href: `#/projects/${p.id}` }, 'Open'));
      if (p.live && p.site_id) links.appendChild(el('a', { class: 'home-proj-link', href: `/sites/s${p.site_id}/current/`, target: '_blank', rel: 'noopener' }, 'View site'));
      const card = el('div', { class: 'home-proj-card' },
        el('a', { class: 'home-proj-main', href: `#/projects/${p.id}` },
          el('div', { class: 'proj-initial' }, (p.name || '?')[0].toUpperCase()),
          el('div', { class: 'home-proj-body' },
            el('div', { class: 'home-proj-name' }, p.name),
            el('div', { class: 'home-proj-meta' },
              `${p.tables} table${p.tables === 1 ? '' : 's'}`,
              p.live ? el('span', { class: 'home-badge home-badge-live' }, 'Live')
                     : (p.has_staging ? el('span', { class: 'home-badge home-badge-staging' }, 'Staging') : null)
            )
          )
        ),
        links
      );
      grid.appendChild(card);
    });
    body.appendChild(grid);
  }

  // Activity.
  if (o.activity && o.activity.length) {
    body.appendChild(el('div', { class: 'home-section-head' },
      el('span', { class: 'sb-section-label' }, 'Recent activity')
    ));
    const feed = el('div', { class: 'home-activity' });
    o.activity.forEach(a => feed.appendChild(renderHomeActivityItem(a)));
    body.appendChild(feed);
  }
}

function buildProjectCard(p) {
  const initial = (p.name || '?')[0].toUpperCase();
  const menuBtn = el('button', { class: 'proj-menu-btn', title: 'More options' }, '⋮');
  const dropdown = el('div', { class: 'proj-menu-drop hidden' },
    el('button', { class: 'dropdown-item dropdown-item-danger' }, 'Delete')
  );
  menuBtn.addEventListener('click', e => {
    e.preventDefault(); e.stopPropagation();
    document.querySelectorAll('.proj-menu-drop').forEach(d => { if (d !== dropdown) d.classList.add('hidden'); });
    dropdown.classList.toggle('hidden');
  });

  const footerLinks = [
    el('a', { class: 'home-proj-link', href: `#/projects/${p.id}` }, 'Open')
  ];
  if (p.live && p.site_id) {
    footerLinks.push(el('a', { class: 'home-proj-link', href: `/sites/s${p.site_id}/current/`, target: '_blank', rel: 'noopener' }, 'View site'));
  }

  const card = el('div', { class: 'proj-card' },
    el('a', { class: 'proj-card-main', href: `#/projects/${p.id}` },
      el('div', { class: 'proj-initial' }, initial),
      el('div', { class: 'proj-card-body' },
        el('div', { class: 'proj-card-name' }, p.name),
        el('div', { class: 'proj-card-meta' },
          `${p.tables ?? 0} table${(p.tables ?? 0) === 1 ? '' : 's'}`,
          p.live ? el('span', { class: 'home-badge home-badge-live' }, 'Live')
                 : (p.has_staging ? el('span', { class: 'home-badge home-badge-staging' }, 'Staging') : null)
        )
      )
    ),
    el('div', { class: 'proj-menu-wrap' }, menuBtn, dropdown),
    el('div', { class: 'proj-card-footer' },
      ...footerLinks,
      el('span', { class: 'proj-card-date' }, fmtDate(p.created_at))
    )
  );

  dropdown.querySelector('button').addEventListener('click', async e => {
    e.preventDefault();
    showDeleteProjectModal(p, () => card.remove());
  });

  return card;
}

async function renderProjects() {
  if (!requireAuth()) return;

  renderLayout(null, '', [el('div', { class: 'page-header' },
    el('h1', { class: 'page-title' }, 'Projects'),
    el('button', { class: 'btn btn-primary', id: 'new-project' }, '+ New Project')
  ), el('div', { id: 'project-list' }, el('div', { class: 'text-muted' }, 'Loading…'))]);

  document.getElementById('new-project').addEventListener('click', () => showNewProjectModal());

  const PAGE_SIZE = 12;
  let offset = 0;
  let hasMore = false;
  let loading = false;
  let observer = null;
  let grid = null;
  let sentinel = null;

  async function loadMore() {
    if (loading || !hasMore) return;
    loading = true;
    sentinel.textContent = 'Loading more…';
    try {
      const res = await Api.get(`/v1/projects?limit=${PAGE_SIZE}&offset=${offset}`);
      const items = res.projects || [];
      items.forEach(p => grid.insertBefore(buildProjectCard(p), sentinel));
      offset += items.length;
      hasMore = !!res.has_more;
      sentinel.textContent = '';
      if (!hasMore) { observer?.disconnect(); sentinel.remove(); }
    } catch (e) {
      sentinel.textContent = 'Couldn\'t load more — scroll to retry.';
    } finally {
      loading = false;
    }
  }

  try {
    const list = document.getElementById('project-list');
    const first = await Api.get(`/v1/projects?limit=${PAGE_SIZE}&offset=0`);
    const items = first.projects || [];

    if (!items.length) {
      list.innerHTML = '';
      list.appendChild(el('div', { class: 'ai-empty-state' },
        el('p', { class: 'text-muted' }, 'No projects yet.'),
        el('button', { class: 'btn btn-secondary', style: 'margin-top:16px', onClick: () => showNewProjectModal() }, 'New Project')
      ));
      return;
    }

    offset  = items.length;
    hasMore = !!first.has_more;

    sentinel = el('div', { class: 'proj-grid-sentinel' });
    grid = el('div', { class: 'proj-grid' }, ...items.map(buildProjectCard), sentinel);

    list.innerHTML = '';
    list.appendChild(grid);

    if (hasMore) {
      observer = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) loadMore();
      }, { rootMargin: '300px' });
      observer.observe(sentinel);
    } else {
      sentinel.remove();
    }
  } catch (e) {
    document.getElementById('project-list').innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function showDeleteProjectModal(project, onDeleted) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';

  const modal = document.createElement('div');
  modal.className = 'modal';
  modal.innerHTML = `
    <div class="modal-title" style="color:var(--danger)">Delete Project</div>
    <p style="margin:8px 0 16px;color:var(--text-muted);font-size:0.9rem">
      This will permanently delete <strong>${project.name}</strong> including all tables, data, and deployed sites.<br>
      Type the project name to confirm:
    </p>
    <div class="form-group">
      <input type="text" id="delete-confirm-input" placeholder="${project.name}" autocomplete="off">
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" id="cancel-delete">Cancel</button>
      <button class="btn btn-danger" id="confirm-delete" disabled>Delete</button>
    </div>
  `;

  const input = modal.querySelector('#delete-confirm-input');
  const confirmBtn = modal.querySelector('#confirm-delete');

  input.addEventListener('input', () => {
    confirmBtn.disabled = input.value !== project.name;
  });

  modal.querySelector('#cancel-delete').addEventListener('click', () => overlay.remove());

  confirmBtn.addEventListener('click', async () => {
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Deleting…';
    try {
      await Api.delete(`/v1/projects/${project.id}`);
      overlay.remove();
      onDeleted();
    } catch (err) {
      confirmBtn.disabled = false;
      confirmBtn.textContent = 'Delete';
      alert(err.message);
    }
  });

  overlay.appendChild(modal);
  document.body.appendChild(overlay);
  input.focus();
}

function showNewProjectModal() {
  const overlay = h(`
    <div class="modal-overlay" id="new-project-modal">
      <div class="modal">
        <div class="modal-title">New Project</div>
        <div class="form-group"><label>Project Name</label><input type="text" id="proj-name" placeholder="my-app"></div>
        <div class="modal-footer">
          <button class="btn btn-secondary" id="cancel">Cancel</button>
          <button class="btn btn-primary" id="create">Create</button>
        </div>
      </div>
    </div>
  `);

  const modalEl = overlay.firstElementChild;
  modalEl.querySelector('#cancel').addEventListener('click', () => modalEl.remove());
  modalEl.querySelector('#create').addEventListener('click', async () => {
    const name = modalEl.querySelector('#proj-name').value.trim();
    if (!name) return;
    try {
      await Api.post('/v1/projects', { name });
      modalEl.remove();
      renderProjects();
    } catch (e) {
      console.error('[SupaBein] Create project failed', e);
      alert(e.message);
    }
  });

  document.body.appendChild(modalEl);
}


// Project overview
async function renderProject({ id }) {
  if (!requireAuth()) return;
  renderLayout(id, '', [el('p', { class: 'text-muted' }, 'Loading…')]);

  try {
    const project = await Api.get(`/v1/projects/${id}`);


    const tabOverview = el('div', { class: 'tab active' }, 'Overview');
    const tabTables   = el('div', { class: 'tab' }, 'Tables');
    const tabApi      = el('div', { class: 'tab' }, 'API');
    const tabDeploy   = el('div', { class: 'tab' }, 'Deploy');

    const paneOverviewEl = el('div', { id: 'pane-proj-overview' });
    const paneTablesEl   = el('div', { id: 'pane-proj-tables', class: 'hidden' });
    const paneApiEl      = el('div', { id: 'pane-proj-api',    class: 'hidden' });
    const paneDeployEl   = el('div', { id: 'pane-proj-deploy', class: 'hidden' });

    const allTabs  = [tabOverview, tabTables, tabApi, tabDeploy];
    const allPanes = [paneOverviewEl, paneTablesEl, paneApiEl, paneDeployEl];

    function switchProjectTab(idx) {
      allTabs.forEach((t, i)  => t.classList.toggle('active', i === idx));
      allPanes.forEach((p, i) => p.classList.toggle('hidden', i !== idx));
    }

    tabOverview.addEventListener('click', () => {
      switchProjectTab(0);
      if (!paneOverviewEl.dataset.loaded) {
        paneOverviewEl.dataset.loaded = '1';
        loadOverviewPane(id, paneOverviewEl, switchProjectTab);
      }
    });
    tabTables.addEventListener('click', () => {
      switchProjectTab(1);
      if (!paneTablesEl.dataset.loaded) {
        paneTablesEl.dataset.loaded = '1';
        loadTablesPane(id, paneTablesEl);
      }
    });
    tabApi.addEventListener('click', () => {
      switchProjectTab(2);
      if (!paneApiEl.dataset.loaded) {
        paneApiEl.dataset.loaded = '1';
        loadApiPane(id, paneApiEl);
      }
    });
    tabDeploy.addEventListener('click', () => {
      switchProjectTab(3);
      if (!paneDeployEl.dataset.loaded) {
        paneDeployEl.dataset.loaded = '1';
        loadDeployPane(id, paneDeployEl);
      }
    });

    const content = [
      el('div', { class: 'page-header' },
        el('h1', { class: 'page-title' }, project.name),
        el('span', { class: 'text-muted', style: 'font-size:0.8rem' },
          `ID ${project.id} · ${fmtDate(project.created_at)}`
        )
      ),


      el('div', { class: 'tabs', style: 'margin-top:24px' }, tabOverview, tabTables, tabApi, tabDeploy),
      paneOverviewEl, paneTablesEl, paneApiEl, paneDeployEl,
    ];

    renderLayout(id, '', content, { projectName: project.name });
    // Eagerly load the default (Overview) tab
    paneOverviewEl.dataset.loaded = '1';
    loadOverviewPane(id, paneOverviewEl, switchProjectTab);
  } catch (e) {
    setApp(`<div class="alert alert-danger">${e.message}</div>`);
  }
}

// Minimal job poller for one-off actions triggered outside the AI panel
// (e.g. the Overview page's "Seed App" button) — no progress card, just
// waits for the job to resolve and hands back its final result.
async function pollJobUntilDone(jobId) {
  let since = 0;
  while (true) {
    await new Promise(r => setTimeout(r, 2000));
    const job = await Api.get(`/v1/ai/jobs/${jobId}?since=${since}`);
    since = job.event_count;
    if (job.status === 'done') return { ok: true, result: job.result };
    if (job.status === 'failed' || job.status === 'cancelled') return { ok: false, error: job.error };
  }
}

async function loadOverviewPane(projectId, container, switchTab) {
  container.innerHTML = '';
  container.appendChild(el('div', { class: 'text-muted' }, 'Loading…'));
  try {
    const o = await Api.get(`/v1/projects/${projectId}/overview`);
    container.innerHTML = '';

    const stat = (val, label) => el('div', { class: 'home-stat' },
      el('div', { class: 'home-stat-num' }, String(val)),
      el('div', { class: 'home-stat-label' }, label)
    );
    container.appendChild(el('div', { class: 'home-stats' },
      stat(o.stats.tables, o.stats.tables === 1 ? 'Table' : 'Tables'),
      stat(o.stats.live ? 'Live' : (o.stats.has_staging ? 'Staging' : '—'), 'Status'),
      stat(fmtDate(o.project.created_at), 'Created')
    ));

    const ctas = el('div', { class: 'overview-ctas' },
      el('button', { class: 'btn btn-ai', onClick: () => AiPanel.open({ projectId: parseInt(projectId) }) }, '✦ Edit with AI')
    );
    if (o.stats.live && o.site_id) {
      ctas.appendChild(el('a', { class: 'btn btn-secondary', href: `/sites/s${o.site_id}/current/`, target: '_blank', rel: 'noopener' }, 'View Site →'));
    }
    if (o.stats.has_staging && o.site_id) {
      ctas.appendChild(el('a', { class: 'btn btn-secondary', href: `/sites/s${o.site_id}/staging/`, target: '_blank', rel: 'noopener' }, 'View Staging →'));
      ctas.appendChild(el('button', { class: 'btn btn-secondary', onClick: () => switchTab(3) }, 'Publish'));
    } else if (!o.stats.live) {
      ctas.appendChild(el('button', { class: 'btn btn-secondary', onClick: () => switchTab(3) }, 'Deploy'));
    }
    if (o.stats.tables > 0) {
      const seedAppBtn = el('button', { class: 'btn btn-secondary' }, '🌱 Seed App');
      seedAppBtn.addEventListener('click', async () => {
        seedAppBtn.disabled = true;
        const orig = seedAppBtn.textContent;
        seedAppBtn.textContent = '⏳ Seeding…';
        try {
          const { job_id } = await Api.post('/v1/ai/seed/job', { project_id: projectId });
          const outcome = await pollJobUntilDone(job_id);
          if (outcome.ok) {
            const seededAnything = (outcome.result.seeded || []).length || (outcome.result.test_accounts || []).length;
            alert(formatSeedResultMessage(outcome.result));
            if (seededAnything) loadOverviewPane(projectId, container, switchTab); // refresh so Clear Seed Data now shows
          } else {
            alert('Seeding failed: ' + (outcome.error || 'try again'));
          }
        } catch (e) {
          alert(e.message);
        } finally {
          seedAppBtn.disabled = false;
          seedAppBtn.textContent = orig;
        }
      });
      ctas.appendChild(seedAppBtn);
    }
    if (o.stats.has_seed_data) {
      const clearSeedBtn = el('button', { class: 'btn btn-secondary' }, 'Clear Seed Data');
      clearSeedBtn.addEventListener('click', async () => {
        if (!confirm('Remove all AI-seeded sample data from this app\'s tables? Rows you or your users entered yourself are not affected.')) return;
        clearSeedBtn.disabled = true;
        try {
          const res = await Api.post(`/v1/projects/${projectId}/seed/clear`, {});
          const counts = Object.entries(res.deleted || {});
          alert(counts.length
            ? 'Cleared: ' + counts.map(([t, n]) => `${t} (${n})`).join(', ')
            : 'No seeded rows found — nothing to clear.');
          loadOverviewPane(projectId, container, switchTab); // refresh to hide the button now that it's empty
        } catch (e) {
          alert(e.message);
        } finally {
          clearSeedBtn.disabled = false;
        }
      });
      ctas.appendChild(clearSeedBtn);
    }
    container.appendChild(ctas);

    container.appendChild(el('div', { class: 'home-section-head' },
      el('span', { class: 'sb-section-label' }, 'Recent activity')
    ));
    if (o.activity && o.activity.length) {
      const feed = el('div', { class: 'home-activity' });
      o.activity.forEach(a => feed.appendChild(renderHomeActivityItem(a)));
      container.appendChild(feed);
    } else {
      container.appendChild(el('div', { class: 'text-muted', style: 'padding:16px 0' }, 'No activity yet.'));
    }
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function loadTablesPane(projectId, container) {
  container.innerHTML = '<div class="text-muted">Loading…</div>';
  try {
    const tables = await Api.get(`/v1/projects/${projectId}/tables`);

    const newTableBtn = el('button', { class: 'btn btn-primary btn-sm' }, '+ New Table');
    newTableBtn.addEventListener('click', () => showNewTableModal(projectId));

    let tableContent;
    if (!tables.length) {
      tableContent = el('div', { class: 'text-muted', style: 'padding:20px 0' }, 'No tables yet.');
    } else {
      tableContent = el('table', { class: 'data-table' },
        el('thead', {}, el('tr', {},
          el('th', {}, 'Name'), el('th', {}, 'Physical Name'), el('th', {}, '')
        )),
        el('tbody', {}, ...tables.map(t =>
          el('tr', {},
            el('td', {}, el('a', { href: `#/projects/${projectId}/tables/${t.table_name}` }, t.table_name)),
            el('td', { class: 'text-muted text-sm' }, t.physical_name),
            el('td', {},
              el('button', {
                class: 'btn btn-sm btn-danger',
                onClick: async () => {
                  if (!confirm(`Drop table "${t.table_name}"?`)) return;
                  try {
                    await Api.delete(`/v1/projects/${projectId}/tables/${t.table_name}`);
                    loadTablesPane(projectId, container);
                  } catch (e) { alert(e.message); }
                }
              }, 'Drop')
            )
          )
        ))
      );
    }

    container.innerHTML = '';
    container.appendChild(el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px' },
      el('span', { class: 'text-muted', style: 'font-size:0.85rem' }, `${tables.length} table${tables.length !== 1 ? 's' : ''}`),
      newTableBtn
    ));
    container.appendChild(tableContent);
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function loadUsersPane(projectId, container) {
  container.innerHTML = '<div class="text-muted">Loading…</div>';
  try {
    const result = await Api.get(`/v1/projects/${projectId}/users`);
    const users = result.users || [];

    async function deleteUser(uid) {
      if (!confirm('Delete this user? This cannot be undone.')) return;
      try {
        await Api.delete(`/v1/projects/${projectId}/users/${uid}`);
        loadUsersPane(projectId, container);
      } catch (e) { alert('Failed to delete user: ' + e.message); }
    }

    const rows = users.length === 0
      ? [el('tr', {}, el('td', { colspan: '4', style: 'text-align:center;color:var(--text-muted);padding:32px' },
          'No end-users yet. Users appear here after they sign up via your app.'))]
      : users.map(u =>
          el('tr', {},
            el('td', {}, u.email),
            el('td', {}, String(u.id)),
            el('td', {}, String(u.created_at ?? '')),
            el('td', {}, el('button', { class: 'btn btn-sm btn-danger', onClick: () => deleteUser(u.id) }, 'Delete'))
          )
        );

    container.innerHTML = '';
    container.appendChild(el('p', { class: 'text-muted', style: 'font-size:0.85rem;margin-bottom:12px' },
      `${users.length} end-user${users.length !== 1 ? 's' : ''} registered`
    ));
    container.appendChild(
      el('div', { class: 'card' },
        el('div', { class: 'table-responsive' },
          el('table', { class: 'table' },
            el('thead', {}, el('tr', {},
              el('th', {}, 'Email'), el('th', {}, 'ID'), el('th', {}, 'Joined'), el('th', {}, '')
            )),
            el('tbody', {}, ...rows)
          )
        )
      )
    );
    container.appendChild(
      el('div', { class: 'card' },
        el('h3', { style: 'margin-top:0;font-size:0.95rem' }, 'Project User Auth API'),
        el('p', { class: 'text-muted', style: 'font-size:0.85rem;margin-bottom:0' },
          "Your app's end-users sign up and log in via the project auth endpoints. See the ",
          el('a', { href: `#/projects/${projectId}/api` }, 'API tab'),
          ' for curl examples.'
        )
      )
    );
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function renderApi({ id }, container) {
  const projectId = id;
  const useContainer = container || document.getElementById('app');
  if (!container) {
    if (!requireAuth()) return;
    renderLayout(projectId, '', [el('p', { class: 'text-muted' }, 'Loading…')]);
  } else {
    container.innerHTML = '<div class="text-muted">Loading…</div>';
  }

  try {
    const project = await Api.get(`/v1/projects/${projectId}`);
    const baseUrl  = window.location.origin;

    let keyDisplay = project.service_key || '(none — create a new project to generate)';
    const keyPre   = el('pre', { class: 'api-code-block', style: 'word-break:break-all;white-space:pre-wrap' }, keyDisplay);

    function copyServiceKey(btn) {
      navigator.clipboard.writeText(project.service_key || '').then(() => {
        const orig = btn.textContent; btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = orig; }, 1500);
      });
    }

    const copyBtn   = el('button', { class: 'btn btn-sm btn-secondary' }, 'Copy');
    const rotateBtn = el('button', { class: 'btn btn-sm btn-danger' }, 'Rotate key');

    copyBtn.onclick   = () => copyServiceKey(copyBtn);
    rotateBtn.onclick = async () => {
      if (!confirm('Rotate the service key? The old key will stop working immediately.')) return;
      try {
        rotateBtn.disabled = true; rotateBtn.textContent = 'Rotating…';
        const res = await Api.post(`/v1/projects/${projectId}/rotate-service-key`, {});
        project.service_key = res.service_key;
        keyPre.textContent  = res.service_key;
      } catch (e) { alert(e.message); }
      finally { rotateBtn.disabled = false; rotateBtn.textContent = 'Rotate key'; }
    };

    const keyCard = el('div', { class: 'api-table-card' },
      el('div', { class: 'api-table-title' }, 'Service Key'),
      el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 12px' },
        'Use the service key from a trusted server or backend script. It bypasses all row-level policies — never expose it in frontend code.'
      ),
      el('div', { style: 'position:relative;margin-bottom:10px' }, keyPre),
      el('div', { style: 'display:flex;gap:8px' }, copyBtn, rotateBtn)
    );

    const curlBlock = (code) => {
      const pre = el('pre', { class: 'api-code-block' }, code);
      const btn = el('button', { class: 'copy-btn btn btn-sm' }, 'Copy');
      btn.onclick = () => { navigator.clipboard.writeText(code).then(() => { const o = btn.textContent; btn.textContent = 'Copied!'; setTimeout(() => btn.textContent = o, 1500); }); };
      return el('div', { style: 'position:relative;margin-bottom:16px' }, pre, btn);
    };

    const sk = project.service_key || '<service_key>';
    const base = `${baseUrl}/api/v1/data/${projectId}`;

    const docsCard = el('div', { class: 'api-table-card' },
      el('div', { class: 'api-table-title' }, 'Data API'),
      el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 14px' },
        'Base URL: ', el('code', {}, `${base}/{table}`)
      ),

      el('div', { class: 'api-section-label' }, 'List rows'),
      curlBlock(`curl "${base}/{table}?limit=20&offset=0"`),

      el('div', { class: 'api-section-label' }, 'List with filter + ordering'),
      curlBlock(`curl "${base}/{table}?status=active&order=created_at.desc&limit=50"`),

      el('div', { class: 'api-section-label' }, 'Get single row'),
      curlBlock(`curl "${base}/{table}/{id}"`),

      el('div', { class: 'api-section-label' }, 'Insert a row'),
      curlBlock(`curl -X POST "${base}/{table}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"col": "value"}'`),

      el('div', { class: 'api-section-label' }, 'Update a row'),
      curlBlock(`curl -X PATCH "${base}/{table}/{id}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"col": "new value"}'`),

      el('div', { class: 'api-section-label' }, 'Delete a row'),
      curlBlock(`curl -X DELETE "${base}/{table}/{id}"`),

      el('div', { class: 'api-section-label' }, 'Login — tables with a PASSWORD column'),
      curlBlock(`curl -X POST "${base}/{table}/login" \\\n  -H "Content-Type: application/json" \\\n  -d '{"email": "user@example.com", "password": "secret"}'`),

      el('p', { class: 'text-muted', style: 'font-size:0.8rem;margin:4px 0 8px' },
        'Returns ', el('code', {}, '{ token, user }'), '. Use token as Bearer in subsequent requests (authenticated role).'
      ),

      el('div', { class: 'api-section-label' }, 'Service key — bypasses all row-level policies'),
      curlBlock(`curl "${base}/{table}" \\\n  -H "Authorization: Bearer ${sk}"`)
    );

    const nodes = [keyCard, docsCard];

    if (!container) {
      renderLayout(projectId, '', [
        el('div', { class: 'page-header' },
          el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${projectId}` }, '← Project'),
          el('h1', { class: 'page-title' }, 'API')
        ),
        ...nodes
      ]);
    } else {
      container.innerHTML = '';
      nodes.forEach(n => container.appendChild(n));
    }
  } catch (e) {
    (container || document.getElementById('app')).innerHTML =
      `<div class="alert alert-danger">${e.message}</div>`;
  }
}

async function loadApiPane(projectId, container) {
  return renderApi({ id: projectId }, container);
}

async function loadDeployPane(projectId, container) {
  container.innerHTML = '<div class="text-muted">Loading…</div>';
  try {
    const sites = await Api.get(`/v1/projects/${projectId}/sites`);

    if (!sites.length) {
      container.innerHTML = '';
      const subdomainInp = el('input', { type: 'text', class: 'input', placeholder: 'my-app' });
      const spaModeInp   = el('input', { type: 'checkbox', id: 'dp-spa-mode' });
      spaModeInp.checked = true;
      const createBtn    = el('button', { class: 'btn btn-primary' }, 'Create Site');
      createBtn.addEventListener('click', async () => {
        const subdomain = subdomainInp.value.trim();
        const spa_mode  = spaModeInp.checked;
        if (!subdomain) return;
        try {
          await Api.post(`/v1/projects/${projectId}/sites`, { subdomain, spa_mode });
          container.dataset.loaded = '';
          loadDeployPane(projectId, container);
        } catch (e) { alert(e.message); }
      });
      container.appendChild(el('div', { class: 'card' },
        el('div', { class: 'card-title' }, 'Create your site'),
        el('div', { class: 'form-group' },
          el('label', {}, 'Subdomain'),
          subdomainInp
        ),
        el('div', { class: 'form-group', style: 'display:flex;align-items:center;gap:8px' },
          spaModeInp,
          el('label', { for: 'dp-spa-mode' }, 'SPA Mode (React/Vue/etc — rewrites all paths to index.html)')
        ),
        createBtn
      ));
      return;
    }

    const site   = sites[0];
    const siteId = site.id;

    const menuBtn  = el('button', { class: 'btn btn-secondary btn-sm', style: 'position:relative' }, '•••');
    const dropdown = el('div', { class: 'dropdown hidden' },
      el('button', { class: 'dropdown-item dropdown-item-danger' }, 'Delete Site')
    );
    menuBtn.addEventListener('click', e => {
      e.stopPropagation();
      dropdown.classList.toggle('hidden');
    });
    document.addEventListener('click', () => dropdown.classList.add('hidden'));
    dropdown.querySelector('button').addEventListener('click', async () => {
      dropdown.classList.add('hidden');
      if (!confirm('Delete this site and all its deploys? This cannot be undone.')) return;
      try {
        await Api.delete(`/v1/projects/${projectId}/sites/${siteId}`);
        container.dataset.loaded = '';
        loadDeployPane(projectId, container);
      } catch (e) { alert(e.message); }
    });

    const deployContentEl = el('div', { id: 'deploy-content' }, 'Loading…');
    container.innerHTML = '';
    container.appendChild(el('div', { style: 'display:flex;justify-content:flex-end;margin-bottom:8px;gap:8px;position:relative' }, menuBtn, dropdown));
    container.appendChild(deployContentEl);

    await loadDeployContent(projectId, siteId);
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

// Tables list
async function renderTables({ id }) {
  if (!requireAuth()) return;

  renderLayout(id, 'tables', [
    el('div', { class: 'page-header' },
      el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${id}` }, '← Project'),
      el('h1', { class: 'page-title' }, 'Tables'),
      el('button', { class: 'btn btn-primary', id: 'new-table' }, '+')
    ),
    el('div', { id: 'table-list' }, 'Loading...')
  ]);

  document.getElementById('new-table').addEventListener('click', () => showNewTableModal(id));

  try {
    const tables = await Api.get(`/v1/projects/${id}/tables`);
    const list   = document.getElementById('table-list');

    if (!tables.length) {
      list.innerHTML = '<div class="text-muted">No tables yet.</div>';
      return;
    }

    const tbl = el('table', { class: 'data-table' },
      el('thead', {}, el('tr', {},
        el('th', {}, 'Name'), el('th', {}, 'Physical Name'), el('th', {}, '')
      )),
      el('tbody', {}, ...tables.map(t =>
        el('tr', {},
          el('td', {}, el('a', { href: `#/projects/${id}/tables/${t.table_name}` }, t.table_name)),
          el('td', { class: 'text-muted text-sm' }, t.physical_name),
          el('td', {},
            el('button', {
              class: 'btn btn-sm btn-danger',
              onClick: async () => {
                if (!confirm(`Drop table "${t.table_name}"?`)) return;
                await Api.delete(`/v1/projects/${id}/tables/${t.table_name}`);
                renderTables({ id });
              }
            }, 'Drop')
          )
        )
      ))
    );

    list.innerHTML = '';
    list.appendChild(tbl);
  } catch (e) {
    document.getElementById('table-list').innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function showNewTableModal(projectId) {
  const overlay = h(`
    <div class="modal-overlay">
      <div class="modal">
        <div class="modal-title">New Table</div>
        <div class="form-group"><label>Table Name</label><input type="text" id="tname" placeholder="posts"></div>
        <div class="modal-footer">
          <button class="btn btn-secondary" id="cancel">Cancel</button>
          <button class="btn btn-primary" id="create">Create</button>
        </div>
      </div>
    </div>
  `);
  const modalElT = overlay.firstElementChild;
  modalElT.querySelector('#cancel').addEventListener('click', () => modalElT.remove());
  modalElT.querySelector('#create').addEventListener('click', async () => {
    const name = modalElT.querySelector('#tname').value.trim();
    if (!name) return;
    try {
      await Api.post(`/v1/projects/${projectId}/tables`, { name });
      modalElT.remove();
      renderTables({ id: projectId });
    } catch (e) { console.error('[SupaBein] Create table failed', e); alert(e.message); }
  });
  document.body.appendChild(modalElT);
}

// Table editor: columns + rows + policies
async function renderTableEditor({ id, name }) {
  if (!requireAuth()) return;

  renderLayout(id, 'tables', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, name),
      el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${id}/tables` }, '← Tables')
    ),
    el('div', { class: 'tabs' },
      el('div', { class: 'tab active', id: 'tab-cols', onClick: () => switchTab('cols') }, 'Columns'),
      el('div', { class: 'tab', id: 'tab-rows', onClick: () => switchTab('rows') }, 'Data'),
      el('div', { class: 'tab', id: 'tab-policies', onClick: () => switchTab('policies') }, 'Policies')
    ),
    el('div', { id: 'tab-content' })
  ]);

  switchTab('cols');

  function switchTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + tab)?.classList.add('active');
    if (tab === 'cols') renderColumnsTab(id, name);
    else if (tab === 'rows') renderRowsTab(id, name);
    else if (tab === 'policies') renderPoliciesTab(id, name);
  }
}

async function renderColumnsTab(projectId, tableName) {
  const content = document.getElementById('tab-content');
  content.innerHTML = 'Loading...';

  const TYPES = ['INT','BIGINT','VARCHAR(255)','TEXT','BOOLEAN','DECIMAL(10,2)','DATETIME','DATE','TIMESTAMP','JSON','FLOAT'];

  try {
    const cols = await Api.get(`/v1/projects/${projectId}/tables/${tableName}/columns`);

    const addForm = h(`
      <div class="card">
        <div class="card-title">Add Column</div>
        <div class="add-col-row">
          <input type="text" id="col-name" placeholder="column_name" class="add-col-name">
          <select id="col-type" class="add-col-type">${TYPES.map(t => `<option>${t}</option>`).join('')}</select>
          <label class="add-col-nullable">
            <input type="checkbox" id="col-nullable" checked> Nullable
          </label>
          <button class="btn btn-primary btn-sm" id="add-col">Add</button>
        </div>
      </div>
    `);

    const addFormEl = addForm.firstElementChild;
    addFormEl.querySelector('#add-col').addEventListener('click', async () => {
      const colName  = addFormEl.querySelector('#col-name').value.trim();
      const dataType = addFormEl.querySelector('#col-type').value;
      const nullable = addFormEl.querySelector('#col-nullable').checked;
      if (!colName) return;
      try {
        await Api.post(`/v1/projects/${projectId}/tables/${tableName}/columns`, { name: colName, type: dataType, nullable });
        renderColumnsTab(projectId, tableName);
      } catch (e) { console.error('[SupaBein] Add column failed', e); alert(e.message); }
    });

    const colsHtml = cols.length
      ? el('table', { class: 'data-table' },
          el('thead', {}, el('tr', {}, el('th', {}, 'Name'), el('th', {}, 'Type'), el('th', {}, 'Nullable'), el('th', {}, ''))),
          el('tbody', {}, ...cols.map(c =>
            el('tr', {},
              el('td', {}, c.col_name),
              el('td', { class: 'text-muted' }, c.data_type),
              el('td', {}, c.nullable ? 'Yes' : 'No'),
              el('td', {},
                el('button', {
                  class: 'btn btn-sm btn-danger',
                  onClick: async () => {
                    if (!confirm(`Drop column "${c.col_name}"?`)) return;
                    await Api.delete(`/v1/projects/${projectId}/tables/${tableName}/columns/${c.col_name}`);
                    renderColumnsTab(projectId, tableName);
                  }
                }, 'Drop')
              )
            )
          ))
        )
      : el('div', { class: 'text-muted' }, 'No custom columns. (id and created_at are always present)');

    content.innerHTML = '';
    content.appendChild(addFormEl);
    content.appendChild(el('div', { class: 'card mt-3' },
      el('div', { class: 'card-title' }, 'Columns'),
      colsHtml
    ));
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function renderRowsTab(projectId, tableName) {
  const content = document.getElementById('tab-content');
  content.innerHTML = 'Loading...';

  let offset    = 0;
  const limit   = 25;
  let cols      = [];
  let filterVal = '';
  let orderVal  = '';

  function openRowModal(title, row, onSave) {
    const overlay = el('div', { class: 'modal-overlay' });
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    const inputs = {};
    const fields = cols.map(c => {
      const inp = el('input', { type: 'text', placeholder: c.data_type });
      if (row) inp.value = String(row[c.col_name] ?? '');
      inputs[c.col_name] = inp;
      return el('div', { class: 'form-group' },
        el('label', {}, c.col_name + ' (' + c.data_type + ')'),
        inp
      );
    });
    const modal = el('div', { class: 'modal' },
      el('div', { class: 'modal-title' }, title),
      ...fields,
      el('div', { class: 'modal-footer' },
        el('button', { class: 'btn btn-secondary', onClick: () => overlay.remove() }, 'Cancel'),
        el('button', { class: 'btn btn-primary', onClick: async () => {
          const data = {};
          for (const [k, inp] of Object.entries(inputs)) data[k] = inp.value;
          try { await onSave(data); overlay.remove(); await refresh(); }
          catch (e) { alert(e.message); }
        }}, 'Save')
      )
    );
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    modal.querySelector('input')?.focus();
  }

  async function refresh() {
    const area = document.getElementById('rows-area');
    if (!area) return;
    area.innerHTML = '<span class="text-muted">Loading…</span>';
    try {
      let qs = `limit=${limit}&offset=${offset}`;
      if (orderVal)  qs += '&order='  + encodeURIComponent(orderVal);
      if (filterVal) qs += '&' + filterVal.replace(/^\?/, '');
      const res  = await Api.get(`/v1/data/${projectId}/${tableName}?${qs}`);
      const rows = res.data || [];
      const displayCols = [{ col_name: 'id' }, { col_name: 'created_at' }, ...cols];

      area.innerHTML = '';

      if (!rows.length) {
        area.appendChild(el('p', { class: 'text-muted mt-2' }, 'No rows match the current filter.'));
      } else {
        area.appendChild(el('div', { style: 'overflow-x:auto' },
          el('table', { class: 'data-table' },
            el('thead', {}, el('tr', {},
              ...displayCols.map(c => el('th', {}, c.col_name)),
              el('th', {}, 'Actions')
            )),
            el('tbody', {}, ...rows.map(r =>
              el('tr', {},
                ...displayCols.map(c =>
                  el('td', { style: 'max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap', title: String(r[c.col_name] ?? '') },
                    String(r[c.col_name] ?? ''))
                ),
                el('td', { style: 'white-space:nowrap;display:flex;gap:4px' },
                  el('button', { class: 'btn btn-sm btn-secondary',
                    onClick: () => openRowModal('Edit Row #' + r.id, r, data =>
                      Api.patch(`/v1/data/${projectId}/${tableName}/${r.id}`, data)
                    )
                  }, 'Edit'),
                  el('button', { class: 'btn btn-sm btn-danger',
                    onClick: async () => {
                      if (!confirm('Delete row #' + r.id + '?')) return;
                      try { await Api.delete(`/v1/data/${projectId}/${tableName}/${r.id}`); await refresh(); }
                      catch (e) { alert(e.message); }
                    }
                  }, 'Del')
                )
              )
            ))
          )
        ));
      }

      area.appendChild(el('div', { style: 'display:flex;align-items:center;gap:12px;margin-top:14px;font-size:13px' },
        el('button', { class: 'btn btn-secondary btn-sm', disabled: offset === 0,
          onClick: () => { offset = Math.max(0, offset - limit); refresh(); }
        }, '← Prev'),
        el('span', { class: 'text-muted' }, `${offset + 1}–${offset + rows.length} · total returned: ${res.count}`),
        el('button', { class: 'btn btn-secondary btn-sm', disabled: rows.length < limit,
          onClick: () => { offset += limit; refresh(); }
        }, 'Next →')
      ));
    } catch (e) {
      area.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
    }
  }

  try {
    cols = await Api.get(`/v1/projects/${projectId}/tables/${tableName}/columns`);

    const filterInp = el('input', { type: 'text', placeholder: 'Filter  e.g. status=active  or  age=gte.18', style: 'flex:1;min-width:180px' });
    const orderInp  = el('input', { type: 'text', placeholder: 'Order  e.g. created_at.desc', style: 'width:170px' });

    content.innerHTML = '';
    content.appendChild(el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px' },
      filterInp, orderInp,
      el('button', { class: 'btn btn-secondary btn-sm', onClick: () => {
        filterVal = filterInp.value.trim(); orderVal = orderInp.value.trim(); offset = 0; refresh();
      }}, 'Apply'),
      el('button', { class: 'btn btn-secondary btn-sm', onClick: () => {
        filterInp.value = ''; orderInp.value = ''; filterVal = ''; orderVal = ''; offset = 0; refresh();
      }}, 'Clear'),
      el('button', { class: 'btn btn-primary btn-sm', style: 'margin-left:auto',
        onClick: () => openRowModal('Insert Row', null, data =>
          Api.post(`/v1/data/${projectId}/${tableName}`, data)
        )
      }, '+')
    ));
    content.appendChild(el('div', { id: 'rows-area' }));
    await refresh();
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function renderPoliciesTab(projectId, tableName) {
  const content = document.getElementById('tab-content');
  content.innerHTML = 'Loading...';

  const OPERATIONS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

  try {
    const policies = await Api.get(`/v1/projects/${projectId}/tables/${tableName}/policies`);

    const policyForm = h(`
      <div class="card">
        <div class="card-title">Add / Update Policy</div>
        <div class="flex gap-2" style="flex-wrap:wrap">
          <div class="form-group" style="width:180px"><label>API Role</label><input type="text" id="p-role" placeholder="anon / authenticated" list="role-options"><datalist id="role-options"><option value="anon"><option value="authenticated"></datalist></div>
          <div class="form-group" style="width:140px"><label>Operation</label><select id="p-op">${OPERATIONS.map(o=>`<option>${o}</option>`).join('')}</select></div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:22px">
            <input type="checkbox" id="p-allowed"> <label for="p-allowed">Allowed</label>
          </div>
        </div>
        <div class="form-group"><label>Constraint SQL <span class="text-muted">(optional, e.g. user_id = :current_user_id)</span></label>
          <input type="text" id="p-constraint" placeholder="user_id = :current_user_id">
        </div>
        <button class="btn btn-primary btn-sm" id="save-policy">Save Policy</button>
      </div>
    `);

    const policyFormEl = policyForm.firstElementChild;
    policyFormEl.querySelector('#save-policy').addEventListener('click', async () => {
      const body = {
        api_role: policyFormEl.querySelector('#p-role').value.trim(),
        operation: policyFormEl.querySelector('#p-op').value,
        allowed: policyFormEl.querySelector('#p-allowed').checked,
        constraint_sql: policyFormEl.querySelector('#p-constraint').value.trim() || null,
      };
      try {
        await Api.put(`/v1/projects/${projectId}/tables/${tableName}/policies`, body);
        renderPoliciesTab(projectId, tableName);
      } catch (e) { console.error('[SupaBein] Save policy failed', e); alert(e.message); }
    });

    const policyTable = policies.length
      ? el('table', { class: 'data-table' },
          el('thead', {}, el('tr', {}, el('th', {}, 'Role'), el('th', {}, 'Op'), el('th', {}, 'Allowed'), el('th', {}, 'Constraint'))),
          el('tbody', {}, ...policies.map(p =>
            el('tr', {},
              el('td', {}, p.api_role),
              el('td', {}, p.operation),
              el('td', {}, p.allowed ? el('span', {class:'badge badge-green'},'Yes') : el('span', {class:'badge badge-red'},'No')),
              el('td', { class: 'text-muted text-sm' }, p.constraint_sql || '—')
            )
          ))
        )
      : el('div', { class: 'text-muted' }, 'No policies defined. All access is denied by default.');

    content.innerHTML = '';
    content.appendChild(policyFormEl);
    content.appendChild(el('div', { class: 'card mt-3' },
      el('div', { class: 'card-title' }, 'Current Policies'),
      policyTable
    ));
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

// Sites
async function renderSites({ id }) {
  if (!requireAuth()) return;

  // Show a minimal loading state while we check
  renderLayout(id, 'sites', [
    el('div', { class: 'page-header' }, el('h1', { class: 'page-title' }, 'Site')),
    el('div', { id: 'site-list' }, 'Loading...')
  ]);

  try {
    const sites = await Api.get(`/v1/projects/${id}/sites`);

    // Any existing site → go straight to the manager
    if (sites.length >= 1) {
      Router.navigate(`/projects/${id}/sites/${sites[0].id}`);
      return;
    }

    // No site yet — show create form
    renderLayout(id, 'sites', [
      el('div', { class: 'page-header' }, el('h1', { class: 'page-title' }, 'Site')),
      el('div', { class: 'card' },
        el('div', { class: 'card-title' }, 'Create your site'),
        el('div', { class: 'form-group' },
          el('label', {}, 'Subdomain'),
          el('input', { type: 'text', id: 'subdomain', placeholder: 'my-app' })
        ),
        el('div', { class: 'form-group', style: 'display:flex;align-items:center;gap:8px' },
          el('input', { type: 'checkbox', id: 'spa-mode', checked: true }),
          el('label', { for: 'spa-mode' }, 'SPA Mode (React/Vue/etc — rewrites all paths to index.html)')
        ),
        el('button', { class: 'btn btn-primary', id: 'create-site' }, 'Create Site')
      )
    ]);

    document.getElementById('create-site').addEventListener('click', async () => {
      const subdomain = document.getElementById('subdomain').value.trim();
      const spa_mode  = document.getElementById('spa-mode').checked;
      if (!subdomain) return;
      try {
        const site = await Api.post(`/v1/projects/${id}/sites`, { subdomain, spa_mode });
        Router.navigate(`/projects/${id}/sites/${site.id}`);
      } catch (e) { alert(e.message); }
    });
  } catch (e) {
    document.getElementById('site-list').innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function showNewSiteModal(projectId) {
  const overlay = h(`
    <div class="modal-overlay">
      <div class="modal">
        <div class="modal-title">New Site</div>
        <div class="form-group"><label>Subdomain</label><input type="text" id="subdomain" placeholder="my-app"></div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="spa-mode" checked>
          <label for="spa-mode">SPA Mode (React/Vue/etc — rewrites all paths to index.html)</label>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" id="cancel">Cancel</button>
          <button class="btn btn-primary" id="create">Create</button>
        </div>
      </div>
    </div>
  `);
  const modalElS = overlay.firstElementChild;
  modalElS.querySelector('#cancel').addEventListener('click', () => modalElS.remove());
  modalElS.querySelector('#create').addEventListener('click', async () => {
    const subdomain = modalElS.querySelector('#subdomain').value.trim();
    const spa_mode  = modalElS.querySelector('#spa-mode').checked;
    if (!subdomain) return;
    try {
      await Api.post(`/v1/projects/${projectId}/sites`, { subdomain, spa_mode });
      modalElS.remove();
      renderSites({ id: projectId });
    } catch (e) { console.error('[SupaBein] Create site failed', e); alert(e.message); }
  });
  document.body.appendChild(modalElS);
}

// Site manager — deploy history + upload
async function renderSiteManager({ id, site_id }) {
  if (!requireAuth()) return;

  const menuBtn = el('button', { class: 'btn btn-secondary btn-sm', id: 'site-menu-btn', style: 'position:relative' }, '•••');
  const dropdown = el('div', { class: 'dropdown hidden', id: 'site-menu-dropdown' },
    el('button', { class: 'dropdown-item dropdown-item-danger', id: 'delete-site-btn' }, 'Delete Site')
  );
  const menuWrap = el('div', { style: 'position:relative' }, menuBtn, dropdown);

  renderLayout(id, 'sites', [
    el('div', { class: 'page-header' },
      el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${id}` }, '← Project'),
      el('h1', { class: 'page-title' }, 'Site'),
      menuWrap
    ),
    el('div', { id: 'deploy-content' }, 'Loading...')
  ]);

  menuBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    dropdown.classList.toggle('hidden');
  });
  document.addEventListener('click', () => dropdown.classList.add('hidden'), { capture: false });

  dropdown.querySelector('#delete-site-btn').addEventListener('click', async () => {
    dropdown.classList.add('hidden');
    if (!confirm('Delete this site and all its deploys? This cannot be undone.')) return;
    try {
      await Api.delete(`/v1/projects/${id}/sites/${site_id}`);
      Router.navigate(`/projects/${id}/sites`);
    } catch (e) { alert(e.message); }
  });

  await loadDeployContent(id, site_id);
}

// Latest test verdict for a project, phrased as a publish warning — null when
// tests pass, were never run, or the check itself fails (never blocks publish).
async function latestTestWarning(projectId) {
  try {
    const ts = await Api.get(`/v1/projects/${projectId}/test-status`);
    if (ts && ts.tested) {
      if (ts.error) return `The last test run errored: ${ts.error}`;
      if (ts.failed > 0) return `${ts.failed} of ${ts.passed + ts.failed} test stories failed on the last run`;
    }
  } catch (_) { /* no verdict — don't block */ }
  return null;
}

async function loadDeployContent(projectId, siteId) {
  const content = document.getElementById('deploy-content');

  try {
    const [site, deploys] = await Promise.all([
      Api.get(`/v1/projects/${projectId}/sites/${siteId}`),
      Api.get(`/v1/projects/${projectId}/sites/${siteId}/deploys`)
    ]);

    const uploadForm = h(`
      <div class="card">
        <div class="card-title">Upload Deploy (zip)</div>
        <div class="form-group">
          <label class="label">Version label (optional)</label>
          <input type="text" id="deploy-label" class="input" placeholder="e.g. v1.0.0 or hotfix-login">
        </div>
        <div class="form-group">
          <label class="label">Zip file</label>
          <input type="file" id="zip-file" accept=".zip">
        </div>
        <button class="btn btn-primary" id="deploy-btn">Deploy to Staging</button>
        <div class="progress-wrap hidden" id="progress-wrap">
          <div class="progress-bar" id="progress-bar"></div>
        </div>
        <div id="upload-status" class="mt-2 text-sm"></div>
      </div>
    `);

    const uploadFormEl = uploadForm.firstElementChild;
    uploadFormEl.querySelector('#deploy-btn').addEventListener('click', async () => {
      const file = uploadFormEl.querySelector('#zip-file').files[0];
      if (!file) { alert('Select a zip file first'); return; }

      const labelVal = uploadFormEl.querySelector('#deploy-label').value.trim();
      const fd = new FormData();
      fd.append('zipfile', file);
      if (labelVal) fd.append('label', labelVal);

      const progressWrap = uploadFormEl.querySelector('#progress-wrap');
      const progressBar  = uploadFormEl.querySelector('#progress-bar');
      const status       = uploadFormEl.querySelector('#upload-status');
      progressWrap.classList.remove('hidden');
      status.textContent = 'Uploading...';

      try {
        await new Promise((resolve, reject) => {
          const xhr = new XMLHttpRequest();
          xhr.open('POST', `/api/v1/projects/${projectId}/sites/${siteId}/deploys`);
          const token = Auth.getToken();
          if (token) xhr.setRequestHeader('Authorization', 'Bearer ' + token);

          xhr.upload.onprogress = e => {
            if (e.lengthComputable) {
              progressBar.style.width = Math.round(e.loaded / e.total * 100) + '%';
            }
          };

          xhr.onload = () => {
            if (xhr.status === 201 || xhr.status === 200) resolve(JSON.parse(xhr.responseText));
            else reject(new Error(JSON.parse(xhr.responseText).error || 'Upload failed'));
          };
          xhr.onerror = () => reject(new Error('Network error'));
          xhr.send(fd);
        });

        status.textContent = 'Staged! Click "Publish to Live" to go live.';
        status.style.color = 'var(--accent)';
        await loadDeployContent(projectId, siteId);
      } catch (e) {
        status.textContent = 'Error: ' + e.message;
        status.style.color = 'var(--danger)';
        progressWrap.classList.add('hidden');
      }
    });

    const currentDeploy = deploys.find(d => d.id === site.current_deploy_id);
    const statusBadge = d => {
      const cls = d.status === 'ready' ? 'green' : d.status === 'failed' ? 'red' : 'yellow';
      return `<span class="badge badge-${cls}">${d.status}</span>`;
    };

    const deployTable = deploys.length
      ? el('table', { class: 'data-table' },
          el('thead', {}, el('tr', {},
            el('th', {}, 'Version'), el('th', {}, 'Status'), el('th', {}, 'Size'), el('th', {}, 'Uploaded'), el('th', {}, '')
          )),
          el('tbody', {}, ...deploys.map((d, idx) => {
            const isCurrent = d.id === site.current_deploy_id;
            const nextReady = deploys.slice(idx + 1).find(x => x.status === 'ready');
            const isStaging = d.id === site.staging_deploy_id;
            const labelEl = el('span', {}, d.version_label || '—');
            if (isCurrent) {
              const chip = el('span', { class: 'badge badge-green', style: 'margin-left:6px;font-size:0.7rem' }, 'live');
              labelEl.appendChild(chip);
            } else if (isStaging) {
              const chip = el('span', { class: 'badge badge-yellow', style: 'margin-left:6px;font-size:0.7rem' }, 'staging');
              labelEl.appendChild(chip);
            } else if (d.status === 'pending') {
              const chip = el('span', { class: 'badge badge-yellow', style: 'margin-left:6px;font-size:0.7rem' }, 'pending');
              labelEl.appendChild(chip);
            }
            const actions = el('td', { style: 'white-space:nowrap' });
            if (isStaging && d.status === 'ready') {
              actions.appendChild(el('button', {
                class: 'btn btn-sm btn-primary',
                style: 'margin-right:6px',
                onClick: async () => {
                  const warn = await latestTestWarning(projectId);
                  if (!confirm((warn ? '⚠ ' + warn + '.\n\n' : '') + 'Publish this staging deploy to live?')) return;
                  try {
                    await Api.post(`/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/publish`);
                    loadDeployContent(projectId, siteId);
                  } catch (e) { alert(e.message); }
                }
              }, 'Publish to Live'));
            }
            if (!isCurrent && !isStaging && d.status === 'ready') {
              actions.appendChild(el('button', {
                class: 'btn btn-sm btn-secondary',
                style: 'margin-right:6px',
                onClick: async () => {
                  if (!confirm('Roll back to this deploy?')) return;
                  try {
                    await Api.post(`/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/rollback`);
                    loadDeployContent(projectId, siteId);
                  } catch (e) { alert(e.message); }
                }
              }, 'Rollback'));
            }
            if (nextReady && d.status === 'ready') {
              actions.appendChild(el('button', {
                class: 'btn btn-sm btn-ghost',
                onClick: async () => {
                  try {
                    const diff = await Api.get(`/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/diff?vs=${nextReady.id}`);
                    const lines = [
                      `Diff: deploy ${d.id} vs ${nextReady.id}`,
                      '',
                      `Added (${diff.added.length}):    ${diff.added.join(', ') || 'none'}`,
                      `Removed (${diff.removed.length}): ${diff.removed.join(', ') || 'none'}`,
                      `Modified (${diff.modified.length}): ${diff.modified.join(', ') || 'none'}`,
                      `Unchanged: ${diff.unchanged}`,
                    ];
                    alert(lines.join('\n'));
                  } catch (e) { alert(e.message); }
                }
              }, 'Diff'));
            }
            if (d.status === 'ready') {
              actions.appendChild(el('button', {
                class: 'btn btn-sm btn-ghost',
                onClick: async (e) => {
                  e.target.disabled = true; e.target.textContent = '…';
                  try {
                    const res = await fetch(`/api/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/download`, {
                      headers: { Authorization: 'Bearer ' + (Auth.getToken() || '') }
                    });
                    if (!res.ok) throw new Error('Download failed');
                    const cd = res.headers.get('Content-Disposition') || '';
                    const fnMatch = cd.match(/filename="([^"]+)"/);
                    const filename = fnMatch ? fnMatch[1] : `deploy-${d.id}.zip`;
                    const blob = await res.blob();
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url; a.download = filename; a.click();
                    URL.revokeObjectURL(url);
                  } catch (err) { alert(err.message); }
                  finally { e.target.disabled = false; e.target.textContent = '↓ ZIP'; }
                }
              }, '↓ ZIP'));
            }
            return el('tr', {},
              el('td', {}, labelEl),
              el('td', {}, ...h(`<span>${statusBadge(d)}</span>`).children),
              el('td', { class: 'text-muted text-sm' }, d.size_bytes ? Math.round(d.size_bytes / 1024) + ' KB' : '—'),
              el('td', { class: 'text-muted text-sm' }, fmtDate(d.uploaded_at)),
              actions
            );
          }))
        )
      : el('div', { class: 'text-muted' }, 'No deploys yet. Upload a zip above.');

    const tabDeploy = el('div', { class: 'tab active', id: 'tab-deploy' }, 'Deploy');
    const tabFiles  = el('div', { class: 'tab', id: 'tab-files' }, 'Files');
    const paneDeployEl = el('div', { id: 'pane-deploy' });
    const paneFilesEl  = el('div', { id: 'pane-files', class: 'hidden' });

    paneDeployEl.appendChild(uploadFormEl);
    paneDeployEl.appendChild(el('div', { class: 'card mt-3' },
      el('div', { class: 'card-title' }, 'Deploy History'),
      deployTable
    ));

    tabDeploy.addEventListener('click', () => {
      tabDeploy.classList.add('active'); tabFiles.classList.remove('active');
      paneDeployEl.classList.remove('hidden'); paneFilesEl.classList.add('hidden');
    });
    tabFiles.addEventListener('click', () => {
      tabFiles.classList.add('active'); tabDeploy.classList.remove('active');
      paneFilesEl.classList.remove('hidden'); paneDeployEl.classList.add('hidden');
      if (!paneFilesEl.dataset.loaded) {
        paneFilesEl.dataset.loaded = '1';
        loadFileBrowser(projectId, siteId, paneFilesEl, '');
      }
    });

    content.innerHTML = '';
    content.appendChild(el('div', { class: 'tabs' }, tabDeploy, tabFiles));
    content.appendChild(paneDeployEl);
    content.appendChild(paneFilesEl);
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

async function loadFileBrowser(projectId, siteId, container, path) {
  container.innerHTML = '<div class="text-muted" style="padding:12px">Loading...</div>';
  try {
    const data = await Api.get(`/v1/projects/${projectId}/sites/${siteId}/browse?path=${encodeURIComponent(path)}`);

    if (data.type === 'dir') {
      // Breadcrumb
      const parts = path ? path.split('/').filter(Boolean) : [];
      const crumbs = [el('span', { class: 'fb-crumb', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, '') }, '/')];
      parts.forEach((p, i) => {
        crumbs.push(el('span', { class: 'text-muted' }, ' / '));
        const crumbPath = parts.slice(0, i + 1).join('/');
        crumbs.push(el('span', { class: 'fb-crumb', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, crumbPath) }, p));
      });

      const rows = data.items.map(item => {
        const icon = item.type === 'dir' ? '📁' : '📄';
        const itemPath = path ? path + '/' + item.name : item.name;
        return el('tr', { class: 'fb-row', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, itemPath) },
          el('td', { style: 'width:24px' }, icon),
          el('td', {}, item.name),
          el('td', { class: 'text-muted text-sm', style: 'text-align:right;width:80px' }, item.size != null ? Math.round(item.size / 1024 * 10) / 10 + ' KB' : '')
        );
      });

      // Back row
      if (path) {
        const parentPath = path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
        rows.unshift(el('tr', { class: 'fb-row', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, parentPath) },
          el('td', {}, '⬆'), el('td', {}, '..'), el('td', {})
        ));
      }

      container.innerHTML = '';
      container.appendChild(el('div', { class: 'card' },
        el('div', { style: 'display:flex;align-items:center;gap:4px;margin-bottom:12px;flex-wrap:wrap' }, ...crumbs),
        data.items.length
          ? el('table', { class: 'data-table' }, el('tbody', {}, ...rows))
          : el('div', { class: 'text-muted' }, 'Empty directory')
      ));
    } else {
      // File viewer
      const parentPath = path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
      const fileName = path.split('/').pop();
      container.innerHTML = '';
      container.appendChild(el('div', { class: 'card' },
        el('div', { style: 'display:flex;align-items:center;gap:8px;margin-bottom:12px' },
          el('span', { class: 'fb-crumb', style: 'cursor:pointer', onClick: () => loadFileBrowser(projectId, siteId, container, parentPath) }, '← Back'),
          el('span', { class: 'text-muted' }, fileName),
          el('span', { class: 'text-muted text-sm' }, data.size != null ? '(' + Math.round(data.size / 1024 * 10) / 10 + ' KB)' : '')
        ),
        data.truncated
          ? el('div', { class: 'alert alert-error' }, 'File too large to display (> 512 KB)')
          : (() => {
              const EXT_LANG = {
                html: 'html', htm: 'html', css: 'css',
                js: 'javascript', mjs: 'javascript', ts: 'typescript',
                json: 'json', php: 'php', md: 'markdown',
                xml: 'xml', svg: 'xml', sh: 'bash', bash: 'bash',
                py: 'python', rb: 'ruby', sql: 'sql', yaml: 'yaml', yml: 'yaml',
              };
              const ext = fileName.split('.').pop().toLowerCase();
              const lang = EXT_LANG[ext] || 'plaintext';
              const pre = document.createElement('pre');
              pre.className = 'fb-content';
              const code = document.createElement('code');
              code.className = `language-${lang}`;
              code.textContent = data.content ?? '';
              pre.appendChild(code);
              if (window.hljs) hljs.highlightElement(code);
              return pre;
            })()
      ));
    }
  } catch (e) {
    container.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function render404() {
  setApp('<div style="padding:48px;text-align:center"><h1 style="font-size:48px;color:var(--muted)">404</h1><p class="text-muted">Page not found</p><a href="#/home">Go home</a></div>');
}

// ─── Account ──────────────────────────────────────────────────────────────────

async function renderAccount() {
  if (!requireAuth()) return;
  renderLayout(null, 'account', [el('p', { class: 'text-muted' }, 'Loading…')]);

  let user = null, pats = [];
  try {
    [user, pats] = await Promise.all([
      Api.get('/v1/auth/me'),
      Api.get('/v1/auth/tokens'),
    ]);
  } catch (e) {
    renderLayout(null, 'account', [el('div', { class: 'alert alert-error' }, e.message)]);
    return;
  }

  // ── User info card ──
  const infoCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'Your Account'),
    el('div', { style: 'margin-top:12px;display:flex;flex-direction:column;gap:8px' },
      el('div', { style: 'display:flex;gap:12px;font-size:0.85rem' },
        el('span', { style: 'color:var(--muted);width:80px;flex-shrink:0' }, 'Email'),
        el('span', {}, user.email)
      ),
      el('div', { style: 'display:flex;gap:12px;font-size:0.85rem' },
        el('span', { style: 'color:var(--muted);width:80px;flex-shrink:0' }, 'Role'),
        el('span', {}, user.role)
      ),
      el('div', { style: 'display:flex;gap:12px;font-size:0.85rem' },
        el('span', { style: 'color:var(--muted);width:80px;flex-shrink:0' }, 'Member since'),
        el('span', {}, parseServerDate(user.created_at)?.toLocaleDateString() || '—')
      )
    )
  );

  // ── PAT card ──
  function copyCode(btn, text) {
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(() => { btn.textContent = orig; }, 1500);
    });
  }

  let patList = [...pats];
  const listEl = el('div', { class: 'pat-list' });

  function renderPatList() {
    listEl.innerHTML = '';
    if (patList.length === 0) {
      listEl.appendChild(el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:8px 0' }, 'No tokens yet.'));
      return;
    }
    patList.forEach(pat => {
      const revokeBtn = el('button', { class: 'btn btn-sm btn-danger' }, 'Revoke');
      revokeBtn.onclick = async () => {
        if (!confirm(`Revoke token "${pat.name}"?`)) return;
        try {
          await Api.delete(`/v1/auth/tokens/${pat.id}`);
          patList = patList.filter(p => p.id !== pat.id);
          renderPatList();
        } catch (e) { alert(e.message); }
      };
      const lastUsed = pat.last_used_at ? (parseServerDate(pat.last_used_at)?.toLocaleDateString() || 'Never') : 'Never';
      listEl.appendChild(
        el('div', { class: 'pat-row' },
          el('span', { class: 'pat-name' }, pat.name),
          el('span', { class: 'text-muted pat-meta' }, `Created ${parseServerDate(pat.created_at)?.toLocaleDateString() || '—'} · Last used: ${lastUsed}`),
          revokeBtn
        )
      );
    });
  }
  renderPatList();

  const nameInput    = el('input', { type: 'text', class: 'form-control', placeholder: 'Token name (e.g. "CI deploy")…', style: 'flex:1' });
  const createBtn    = el('button', { class: 'btn btn-primary' }, '+ New Token');
  const tokenDisplay = el('div', { style: 'display:none;margin-top:12px' });

  createBtn.onclick = async () => {
    const name = nameInput.value.trim();
    if (!name) { nameInput.focus(); return; }
    try {
      createBtn.disabled = true;
      const res = await Api.post('/v1/auth/tokens', { name });
      nameInput.value = '';
      const tokenPre = el('pre', { class: 'api-code-block' }, res.token);
      const copyBtn2 = el('button', { class: 'copy-btn btn btn-sm' }, 'Copy');
      copyBtn2.onclick = () => copyCode(copyBtn2, res.token);
      tokenDisplay.style.display = '';
      tokenDisplay.innerHTML = '';
      tokenDisplay.appendChild(
        el('div', {},
          el('p', { style: 'font-size:0.8rem;color:#f59e0b;margin-bottom:6px' }, '⚠ Copy this token now — it will not be shown again.'),
          el('div', { style: 'position:relative' }, tokenPre, copyBtn2)
        )
      );
      const freshPats = await Api.get('/v1/auth/tokens');
      patList = freshPats;
      renderPatList();
    } catch (e) { alert(e.message); }
    finally { createBtn.disabled = false; }
  };

  const patCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'Personal Access Tokens'),
    el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 14px' },
      'PATs authenticate as you across all projects — use them in scripts or CI/CD instead of your password. Token values are shown only once at creation.'
    ),
    listEl,
    el('div', { style: 'display:flex;gap:8px;margin-top:14px' }, nameInput, createBtn),
    tokenDisplay
  );

  // ── Change password card ──
  const pwFields = {
    current: el('input', { type: 'password', class: 'form-control', placeholder: 'Current password' }),
    next:    el('input', { type: 'password', class: 'form-control', placeholder: 'New password (min 8 chars)' }),
    msg:     el('div', { style: 'font-size:0.82rem;min-height:18px' }),
  };
  const pwBtn = el('button', { class: 'btn btn-sm btn-primary' }, 'Change Password');
  pwBtn.addEventListener('click', async () => {
    pwFields.msg.textContent = '';
    pwFields.msg.style.color = '';
    pwBtn.disabled = true; pwBtn.textContent = 'Saving…';
    try {
      await Api.patch('/v1/auth/password', {
        current_password: pwFields.current.value,
        new_password: pwFields.next.value,
      });
      pwFields.current.value = ''; pwFields.next.value = '';
      pwFields.msg.textContent = 'Password changed successfully.';
      pwFields.msg.style.color = 'var(--success, #16a34a)';
    } catch (e) {
      pwFields.msg.textContent = e.message;
      pwFields.msg.style.color = 'var(--danger, #dc2626)';
    }
    pwBtn.disabled = false; pwBtn.textContent = 'Change Password';
  });

  const pwCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'Change Password'),
    el('div', { style: 'display:flex;flex-direction:column;gap:10px;margin-top:12px' },
      el('div', { class: 'form-group', style: 'margin:0' }, pwFields.current),
      el('div', { class: 'form-group', style: 'margin:0' }, pwFields.next),
      el('div', { style: 'display:flex;align-items:center;gap:12px' }, pwBtn, pwFields.msg)
    )
  );

  makeCollapsible(pwCard);
  makeCollapsible(patCard);

  renderLayout(null, 'account', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, 'Account'),
      el('a', { href: '/docs', target: '_blank', rel: 'noopener', class: 'btn btn-sm' }, 'API Docs ↗')
    ),
    infoCard,
    pwCard,
    patCard,
  ]);
}

// ─── Routes ──────────────────────────────────────────────────────────────────

Router.add('', () => {
  if (Auth.isLoggedIn()) Router.navigate('/home');
  else renderLanding();
});

Router.add('login',  renderLogin);
Router.add('signup', renderSignup);
Router.add('forgot', renderForgot);
Router.add('reset',  renderReset);
Router.add('reset/:token', renderReset);

Router.add('logout', () => {
  Auth.clear();
  Router.navigate('/login');
});

Router.add('home',                                  renderHome);
Router.add('projects',                              renderProjects);
Router.add('projects/:id',                          renderProject);
Router.add('projects/:id/tables',                   renderTables);
Router.add('projects/:id/tables/:name',             renderTableEditor);
Router.add('projects/:id/sites',                    renderSites);
Router.add('projects/:id/sites/:site_id',           renderSiteManager);
Router.add('projects/:id/api',                      renderApi);
Router.add('account',                               renderAccount);

// ─── Boot ─────────────────────────────────────────────────────────────────────

document.addEventListener('click', () => {
  document.querySelectorAll('.proj-menu-drop:not(.hidden)').forEach(d => d.classList.add('hidden'));
});

document.addEventListener('DOMContentLoaded', () => {
  Router.init();
  // Belt-and-suspenders for the manifest's orientation lock: installed PWAs
  // often only re-read manifest fields at install time or on the browser's
  // own periodic re-check, not the moment the file changes on the server.
  // This applies immediately for anyone already running in standalone mode
  // (it silently no-ops in a plain browser tab, which can't lock orientation).
  // Some engines reject the call this early (before first paint) or drop the
  // lock when the app is backgrounded then resumed, so retry on 'load' and
  // whenever the page becomes visible again, not just once at DOMContentLoaded.
  const tryLockPortrait = () => { try { screen.orientation?.lock?.('portrait')?.catch(() => {}); } catch (_) {} };
  tryLockPortrait();
  window.addEventListener('load', tryLockPortrait);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) tryLockPortrait(); });
});

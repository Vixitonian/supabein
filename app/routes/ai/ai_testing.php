<?php

declare(strict_types=1);



// ─── Playwright user-story test generation ────────────────────────────────────

function ai_playwright_test_generate(
    string $appUrl,
    string $token,
    array  $schema,
    string $indexHtml,
    int    $projectId,
    string $storyBlock = ''
): string {
    // ── Schema analysis ────────────────────────────────────────────────────────────
    $authTable  = null;
    $authField  = 'email';
    $dataTables = [];
    foreach ($schema['tables'] as $table) {
        $hasPassword  = false;
        $firstVarchar = null;
        foreach ($table['columns'] as $col) {
            if ($col['type'] === 'PASSWORD') {
                $hasPassword = true;
            } elseif ($firstVarchar === null && preg_match('/^VARCHAR|^TEXT/i', $col['type'])) {
                $firstVarchar = $col['name'];
            }
        }
        if ($hasPassword) {
            $authTable = $table['name'];
            $authField = $firstVarchar ?? 'email';
        } else {
            $dataTables[] = $table;
        }
    }
    $hasAuth = $authTable !== null;

    // ── Route detection from deployed index.html ───────────────────────────────────
    $newRoute        = null;
    $itemPrefix      = null;
    $editRouteExists = false;
    if (preg_match_all('/defineRoute\([\'"]([^\'"]+)[\'"]/u', $indexHtml, $rm)) {
        foreach ($rm[1] as $r) {
            if (str_ends_with($r, '/new') && $newRoute === null) {
                $newRoute   = $r;
                $itemPrefix = substr($r, 0, -4);
            }
        }
        if ($itemPrefix) {
            foreach ($rm[1] as $r) {
                if (str_starts_with($r, $itemPrefix . '/') && str_ends_with($r, '/edit')) {
                    $editRouteExists = true;
                    break;
                }
            }
        }
    }

    // ── Fill lines for Record A and Record B ───────────────────────────────────────
    $firstTable    = $dataTables[0] ?? null;
    $fillLines     = [];
    $fillLines2    = [];
    $assertValue   = 'PW Test Value';
    $assertValue2  = 'PW Record B';
    $firstInputSel = 'input';
    $firstField    = true;
    if ($firstTable) {
        foreach ($firstTable['columns'] as $col) {
            $cn = strtolower($col['name']);
            $ct = strtoupper($col['type']);
            if ($ct === 'PASSWORD' || $cn === 'user_id') continue;
            if ($ct === 'BOOLEAN' || $ct === 'TINYINT(1)') continue;

            if (preg_match('/^TEXT|MEDIUMTEXT|LONGTEXT/', $ct)) {
                $tag  = 'textarea';
                $valA = 'PW test content for ' . $col['name'];
                $valB = 'PW record B content ' . $col['name'];
            } elseif (preg_match('/^(INT|BIGINT|SMALLINT|TINYINT)/', $ct)) {
                $tag  = 'input';
                $valA = '42';
                $valB = '99';
            } elseif (preg_match('/^DECIMAL|^FLOAT|^DOUBLE/', $ct)) {
                $tag  = 'input';
                $valA = '9.99';
                $valB = '19.99';
            } elseif ($ct === 'DATE') {
                $tag  = 'input';
                $valA = '2024-01-15';
                $valB = '2024-02-20';
            } elseif (preg_match('/^DATETIME|^TIMESTAMP/', $ct)) {
                $tag  = 'input';
                $valA = '2024-01-15T10:00';
                $valB = '2024-02-20T14:00';
            } else {
                $tag  = 'input';
                $valA = 'Playwright Test ' . ucfirst(str_replace('_', ' ', $col['name']));
                $valB = 'Record B ' . ucfirst(str_replace('_', ' ', $col['name']));
            }

            if ($firstField) {
                $assertValue   = $valA;
                $assertValue2  = $valB;
                $firstInputSel = $tag . '[name="' . $col['name'] . '"]';
                $firstField    = false;
            }
            $fillLines[]  = "  await page.fill('" . $tag . '[name="' . $col['name'] . '"]' . "', "
                          . json_encode($valA, JSON_UNESCAPED_UNICODE) . ');';
            $fillLines2[] = "  await page.fill('" . $tag . '[name="' . $col['name'] . '"]' . "', "
                          . json_encode($valB, JSON_UNESCAPED_UNICODE) . ');';
        }
    }

    $hasCrud   = $firstTable !== null && $newRoute !== null;
    $tokenJson = json_encode($token);
    $urlJson   = json_encode($appUrl);
    $avJson    = json_encode($assertValue);
    $av2Json   = json_encode($assertValue2);
    $fillStr   = implode("\n", $fillLines);
    $fillStr2  = implode("\n", $fillLines2);
    $ipfx      = $itemPrefix ?? '/item';

    $newBtnSel = $newRoute
        ? 'a[href="#' . $newRoute . '"], a:has-text("New")'
        : 'a:has-text("New")';

    $authTableJson   = json_encode($authTable ?? 'users');
    $firstTableJson  = json_encode($firstTable['name'] ?? null);

    // For list assertion: if edit route exists record A will have been edited
    if ($editRouteExists) {
        $listAssertJson = json_encode('PW Edited ' . $assertValue);
        $editValJson    = json_encode('PW Edited ' . $assertValue);
    } else {
        $listAssertJson = $avJson;
        $editValJson    = $avJson;
    }

    // ── Static JS header (helpers) ─────────────────────────────────────────────────
    $header = <<<'JSEOF'
import { chromium } from 'playwright-core';
import https from 'https';
import http from 'http';

let passed = 0, failed = 0;
const stories = [];

function log(msg) { console.log('\n' + msg); }
function assert(label, cond, detail) {
  const rec = { label, passed: Boolean(cond) };
  if (detail) rec.detail = detail;
  stories.push(rec);
  if (cond) { console.log('  ✓  ' + label); passed++; }
  else       { console.error('  ✗  ' + label + (detail ? ' — ' + detail : '')); failed++; }
}

async function captureToken(pg) {
  try {
    const raw = await pg.evaluate(() => localStorage.getItem('sb:token'));
    if (!raw) return [null, null];
    // Same base64url (RFC 7519) fix as the generated app's own auth.js/api.js
    // -- plain atob() throws on a payload containing '-' or '_', which used
    // to make this test helper itself unreliable for exactly the tokens most
    // worth testing.
    const b64 = raw.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const p = JSON.parse(atob(b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '=')));
    return [raw, parseInt(p.sub, 10)];
  } catch (_) { return [null, null]; }
}

async function waitLoggedIn(pg, ms = 15000) {
  await pg.waitForFunction(
    () => { const el = document.querySelector('#nav-logout'); return el && !el.classList.contains('hidden'); },
    { timeout: ms }
  );
}

function apiRequest(method, apiUrl, bearerToken) {
  return new Promise((resolve) => {
    let u;
    try { u = new URL(apiUrl); } catch (_) { resolve(0); return; }
    const isHttps = u.protocol === 'https:';
    const opts = {
      hostname: u.hostname,
      port: u.port ? parseInt(u.port) : (isHttps ? 443 : 80),
      path: u.pathname,
      method,
      headers: { 'Authorization': 'Bearer ' + bearerToken }
    };
    const req = (isHttps ? https : http).request(opts, res => { res.resume(); resolve(res.statusCode); });
    req.on('error', () => resolve(0));
    req.end();
  });
}
const apiDelete = (apiUrl, bearerToken) => apiRequest('DELETE', apiUrl, bearerToken);
const apiGet    = (apiUrl, bearerToken) => apiRequest('GET', apiUrl, bearerToken);
JSEOF;

    // ── Dynamic constants ──────────────────────────────────────────────────────────
    // PID is the numeric project id — the data API casts :project_id to int, so
    // the old 'p<id>' form silently resolved to project 0 and cleanup never worked.
    $constants = 'const TOKEN      = ' . $tokenJson . ";\n"
               . 'const APP_URL    = ' . $urlJson . ";\n"
               . 'const PID        = ' . $projectId . ";\n"
               . 'const AUTH_TABLE = ' . $authTableJson . ";\n"
               . 'const FIRST_TABLE = ' . $firstTableJson . ";\n"
               . 'const TEST_EMAIL   = `pw-a-${Date.now()}@testmail.dev`;' . "\n"
               . 'const TEST_EMAIL_B = `pw-b-${Date.now() + 1}@testmail.dev`;' . "\n"
               . "const TEST_PASS    = 'TestPass123!';\n"
               . "const SB_API = (() => { try { return new URL(APP_URL).origin + '/api/v1'; } catch(_) { return ''; } })();\n";

    // ── Browser connection ─────────────────────────────────────────────────────────
    $connect = <<<'JSEOF'
let tokenA = null, userIdA = null;
let tokenB = null, userIdB = null;
let browser, page;
try {
  browser = await chromium.connectOverCDP(`wss://chrome.browserless.io?token=${TOKEN}`);
  page    = await browser.newPage();
} catch (connErr) {
  // A distinct marker, NOT a fake failing story -- Browserless free-tier
  // quota exhaustion (or any other connect-level failure) is a test
  // INFRASTRUCTURE problem, not evidence the app itself is broken. Folding
  // it into the stories array (as a bare failed assertion used to) made a
  // quota error indistinguishable from a real bug to every downstream
  // consumer, including the auto-fix loop's "still failing after N
  // attempts" verdict -- live-observed giving up on a real bug that may
  // never have existed, because the very last retest couldn't even connect.
  console.log('__CONNECTION_ERROR__' + JSON.stringify({ message: connErr.message }));
  console.log('__STORIES_JSON__' + JSON.stringify(stories));
  process.exit(1);
}
page.setDefaultTimeout(15000);
const pageErrors = [];
page.on('console', m => { if (m.type() === 'error') pageErrors.push(m.text()); });
page.on('pageerror', e => pageErrors.push(e.message));
JSEOF;

    // ── Auth block ─────────────────────────────────────────────────────────────────
    // Login and signup are platform-provided, separate routes (features/auth/auth.js,
    // see ai_routes.php's AI_CANONICAL_AUTH_JS) — same #auth-form/#auth-identifier/
    // #auth-password/#auth-error ids regardless of which route rendered them, so tests
    // navigate to the right route first rather than assuming a combined page.
    $authBlock = '';
    if ($hasAuth) {
        $authBlock .= "  log('Story: Wrong credentials are rejected');\n";
        $authBlock .= "  await page.goto(APP_URL + '#/login', { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(500);\n";
        $authBlock .= "  await page.fill('#auth-form #auth-identifier', 'wrong@nowhere.dev');\n";
        $authBlock .= "  await page.fill('#auth-form #auth-password', 'BadPass000');\n";
        $authBlock .= "  await page.click('#auth-form button[type=\"submit\"]');\n";
        $authBlock .= "  await page.waitForTimeout(2000);\n";
        $authBlock .= "  const stillLogin = page.url().includes('/login');\n";
        $authBlock .= "  const loginErrTxt = await page.\$eval('#auth-error', el => el.textContent.trim()).catch(() => '');\n";
        $authBlock .= "  assert('Wrong credentials are rejected',\n";
        $authBlock .= "    stillLogin || loginErrTxt.length > 0, loginErrTxt);\n\n";

        $authBlock .= "  log('Story: Unauthenticated access shows login form');\n";
        $authBlock .= "  await page.goto(APP_URL + '#/login', { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(1000);\n";
        $authBlock .= "  assert('Login form visible to unauthenticated users', await page.\$('#auth-form') !== null);\n\n";

        $authBlock .= "  log('Story: User can sign up');\n";
        $authBlock .= "  await page.goto(APP_URL + '#/signup', { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(500);\n";
        $authBlock .= "  await page.fill('#auth-form #auth-identifier', TEST_EMAIL);\n";
        $authBlock .= "  await page.fill('#auth-form #auth-password', TEST_PASS);\n";
        $authBlock .= "  await page.click('#auth-form button[type=\"submit\"]');\n";
        $authBlock .= "  let loggedIn = false;\n";
        $authBlock .= "  try {\n";
        $authBlock .= "    await waitLoggedIn(page);\n";
        $authBlock .= "    loggedIn = true;\n";
        $authBlock .= "    [tokenA, userIdA] = await captureToken(page);\n";
        $authBlock .= "  } catch (_) {\n";
        $authBlock .= "    const errTxt = await page.\$eval('#auth-error', el => el.textContent.trim()).catch(() => '');\n";
        $authBlock .= "    console.log('  signup-error: ' + errTxt);\n";
        $authBlock .= "  }\n";
        $authBlock .= "  assert('Signup succeeds and user is logged in', loggedIn);\n";
        $authBlock .= "  if (!loggedIn) throw Object.assign(new Error('auth_failed'), { abort: true });\n\n";

        // A prior real bug (a plain atob() on a base64url JWT payload, fixed
        // in AI_CANONICAL_AUTH_JS's loadUser()) only ever manifested on a
        // genuine full page reload -- every other story here navigates via
        // page.goto() to a same-origin hash-only URL, which browsers treat as
        // in-document SPA navigation and never re-runs loadUser() at all, so
        // this exact class of bug was invisible to every test in this script
        // until now. page.reload() forces the real thing: a full script
        // re-parse against whatever's already sitting in localStorage.
        $authBlock .= "  log('Story: Session survives a page reload');\n";
        $authBlock .= "  await page.reload({ waitUntil: 'networkidle' });\n";
        $authBlock .= "  let stillLoggedInAfterReload = false;\n";
        $authBlock .= "  try { await waitLoggedIn(page); stillLoggedInAfterReload = true; } catch (_) {}\n";
        $authBlock .= "  assert('Still logged in after a page reload', stillLoggedInAfterReload,\n";
        $authBlock .= "    stillLoggedInAfterReload ? '' : 'Reloading the page logged the user out — check the token decode in loadUser()');\n\n";
    } else {
        $authBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $authBlock .= "  await page.waitForTimeout(1000);\n";
    }

    // ── CRUD block ─────────────────────────────────────────────────────────────────
    $crudBlock = '';
    if ($hasCrud) {
        $crudBlock .= "  log('Story: App loads with new-record button');\n";
        $crudBlock .= "  await page.waitForTimeout(500);\n";
        $crudBlock .= "  assert('New record button is visible', await page.\$('" . $newBtnSel . "') !== null);\n";
        $crudBlock .= "  assert('No API fetch errors on load',\n";
        $crudBlock .= "    !pageErrors.some(e => e.toLowerCase().includes('failed to fetch')),\n";
        $crudBlock .= "    pageErrors.slice(0, 2).join(' | '));\n\n";

        // XSS check
        $crudBlock .= "  log('Story: XSS payload in form field does not execute');\n";
        $crudBlock .= "  await page.click('" . $newBtnSel . "');\n";
        $crudBlock .= "  await page.waitForTimeout(600);\n";
        $crudBlock .= "  await page.evaluate(() => { window.__xss = false; });\n";
        $crudBlock .= "  const xssInput = await page.\$('" . $firstInputSel . "');\n";
        $crudBlock .= "  if (xssInput) await xssInput.fill('<img src=x onerror=\"window.__xss=true\">');\n";
        $crudBlock .= "  await page.waitForTimeout(800);\n";
        $crudBlock .= "  const xssRan = await page.evaluate(() => window.__xss === true).catch(() => false);\n";
        $crudBlock .= "  assert('XSS payload in form field does not execute', !xssRan);\n";
        $crudBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $crudBlock .= "  await page.waitForTimeout(500);\n\n";

        // Empty form / submit button check
        $crudBlock .= "  log('Story: Form renders and empty submit causes no JS errors');\n";
        $crudBlock .= "  const errsBefore = pageErrors.length;\n";
        $crudBlock .= "  await page.click('" . $newBtnSel . "');\n";
        $crudBlock .= "  await page.waitForTimeout(600);\n";
        $crudBlock .= "  assert('New record form has a submit button',\n";
        $crudBlock .= "    await page.\$('button[type=\"submit\"], input[type=\"submit\"]') !== null);\n";
        $crudBlock .= "  await page.click('button[type=\"submit\"]').catch(() => {});\n";
        $crudBlock .= "  await page.waitForTimeout(800);\n";
        $crudBlock .= "  const errsAfterEmpty = pageErrors.slice(errsBefore);\n";
        $crudBlock .= "  assert('Submitting empty form causes no new JS errors',\n";
        $crudBlock .= "    errsAfterEmpty.every(e => !e.toLowerCase().includes('typeerror') && !e.toLowerCase().includes('uncaught')),\n";
        $crudBlock .= "    errsAfterEmpty.join(' | '));\n";
        $crudBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $crudBlock .= "  await page.waitForTimeout(500);\n\n";

        // Create record A
        $crudBlock .= "  log('Story: User can create a record');\n";
        $crudBlock .= "  await page.click('" . $newBtnSel . "');\n";
        $crudBlock .= "  await page.waitForTimeout(600);\n";
        if ($fillStr) $crudBlock .= $fillStr . "\n";
        $crudBlock .= "  await page.click('button[type=\"submit\"]');\n";
        $crudBlock .= "  try {\n";
        $crudBlock .= "    await page.waitForFunction(\n";
        $crudBlock .= "      () => window.location.hash.includes('" . $ipfx . "/') && !window.location.hash.includes('/new'),\n";
        $crudBlock .= "      { timeout: 8000 }\n";
        $crudBlock .= "    );\n";
        $crudBlock .= "  } catch (_) {}\n";
        $crudBlock .= "  assert('After create, navigated to detail view',\n";
        $crudBlock .= "    page.url().includes('" . $ipfx . "/') && !page.url().includes('/new'), 'url: ' + page.url());\n";
        $crudBlock .= "  // Record A's id, used later for direct-API policy checks\n";
        $crudBlock .= "  const recAIdMatch = page.url().match(/\\/(\\d+)(?:[?#].*)?\$/);\n";
        $crudBlock .= "  const recAId = recAIdMatch ? recAIdMatch[1] : null;\n\n";

        $crudBlock .= "  log('Story: Created record content is displayed');\n";
        $crudBlock .= "  const bodyTxtA = await page.textContent('body').catch(() => '');\n";
        $crudBlock .= "  assert('Record A content shown on detail view', bodyTxtA.includes(" . $avJson . "));\n\n";

        // Edit record (if edit route detected)
        if ($editRouteExists) {
            $crudBlock .= "  log('Story: User can edit a record');\n";
            $crudBlock .= "  const editLink = await page.\$('a:has-text(\"Edit\"), a[href*=\"/edit\"], button:has-text(\"Edit\")');\n";
            $crudBlock .= "  assert('Edit link/button is present on detail view', editLink !== null);\n";
            $crudBlock .= "  if (editLink) {\n";
            $crudBlock .= "    await editLink.click();\n";
            $crudBlock .= "    await page.waitForTimeout(600);\n";
            $crudBlock .= "    const editField = await page.\$('" . $firstInputSel . "');\n";
            $crudBlock .= "    if (editField) await editField.fill(" . $editValJson . ");\n";
            $crudBlock .= "    await page.click('button[type=\"submit\"]');\n";
            $crudBlock .= "    await page.waitForTimeout(2000);\n";
            $crudBlock .= "    const editedBody = await page.textContent('body').catch(() => '');\n";
            $crudBlock .= "    assert('Edited value shown after save', editedBody.includes(" . $editValJson . "),\n";
            $crudBlock .= "      'got: ' + editedBody.substring(0, 150));\n";
            $crudBlock .= "  }\n\n";
        }

        // List view — record A
        $crudBlock .= "  log('Story: Record appears in list view');\n";
        $crudBlock .= "  await page.click('a:has-text(\"Back\"), a[href=\"#/\"]');\n";
        $crudBlock .= "  await page.waitForTimeout(1200);\n";
        $crudBlock .= "  const listTxtA = await page.textContent('body').catch(() => '');\n";
        $crudBlock .= "  assert('Record A is visible in list', listTxtA.includes(" . $listAssertJson . "));\n\n";

        // Open record from list
        $crudBlock .= "  log('Story: User can open a record from list');\n";
        $crudBlock .= "  const listItemLink = await page.\$('a[href*=\"" . $ipfx . "/\"]');\n";
        $crudBlock .= "  if (listItemLink) {\n";
        $crudBlock .= "    await listItemLink.click();\n";
        $crudBlock .= "    await page.waitForTimeout(800);\n";
        $crudBlock .= "    assert('Clicking record in list opens detail view',\n";
        $crudBlock .= "      page.url().includes('" . $ipfx . "/') && !page.url().includes('/new'));\n";
        $crudBlock .= "    await page.click('a:has-text(\"Back\"), a[href=\"#/\"]').catch(() => {});\n";
        $crudBlock .= "    await page.waitForTimeout(800);\n";
        $crudBlock .= "  }\n\n";

        // Create record B — verify both appear
        $crudBlock .= "  log('Story: Multiple records appear in list');\n";
        $crudBlock .= "  await page.click('" . $newBtnSel . "');\n";
        $crudBlock .= "  await page.waitForTimeout(600);\n";
        if ($fillStr2) $crudBlock .= $fillStr2 . "\n";
        $crudBlock .= "  await page.click('button[type=\"submit\"]');\n";
        $crudBlock .= "  try {\n";
        $crudBlock .= "    await page.waitForFunction(\n";
        $crudBlock .= "      () => window.location.hash.includes('" . $ipfx . "/') && !window.location.hash.includes('/new'),\n";
        $crudBlock .= "      { timeout: 8000 }\n";
        $crudBlock .= "    );\n";
        $crudBlock .= "  } catch (_) {}\n";
        $crudBlock .= "  await page.click('a:has-text(\"Back\"), a[href=\"#/\"]').catch(() => {});\n";
        $crudBlock .= "  await page.waitForTimeout(1200);\n";
        $crudBlock .= "  const listTxtBoth = await page.textContent('body').catch(() => '');\n";
        $crudBlock .= "  assert('Record A still in list after adding Record B', listTxtBoth.includes(" . $listAssertJson . "));\n";
        $crudBlock .= "  assert('Record B is visible in list', listTxtBoth.includes(" . $av2Json . "));\n\n";

        // Mobile viewport
        $crudBlock .= "  log('Story: App works on mobile viewport (375px)');\n";
        $crudBlock .= "  {\n";
        $crudBlock .= "    const mobilePage = await browser.newPage();\n";
        $crudBlock .= "    mobilePage.setDefaultTimeout(12000);\n";
        $crudBlock .= "    await mobilePage.setViewportSize({ width: 375, height: 667 });\n";
        if ($hasAuth) {
            $crudBlock .= "    await mobilePage.goto(APP_URL, { waitUntil: 'networkidle' });\n";
            $crudBlock .= "    if (tokenA) await mobilePage.evaluate(t => localStorage.setItem('sb:token', t), tokenA);\n";
            $crudBlock .= "    await mobilePage.reload({ waitUntil: 'networkidle' });\n";
        } else {
            $crudBlock .= "    await mobilePage.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        }
        $crudBlock .= "    await mobilePage.waitForTimeout(1000);\n";
        $crudBlock .= "    const mOverflow = await mobilePage.evaluate(() => document.body.scrollWidth > window.innerWidth).catch(() => false);\n";
        $crudBlock .= "    assert('No horizontal overflow on mobile viewport', !mOverflow);\n";
        $crudBlock .= "    const mNewBtn = await mobilePage.\$('" . $newBtnSel . "');\n";
        $crudBlock .= "    assert('New record button accessible on mobile', mNewBtn !== null);\n";
        $crudBlock .= "    await mobilePage.close();\n";
        $crudBlock .= "  }\n\n";
    }

    // ── Multi-user isolation block ─────────────────────────────────────────────────
    $isolationBlock = '';
    if ($hasAuth && $hasCrud) {
        $isolationBlock .= "  log('Story: Multi-user data isolation');\n";
        $isolationBlock .= "  {\n";
        $isolationBlock .= "    const pageB = await browser.newPage();\n";
        $isolationBlock .= "    pageB.setDefaultTimeout(15000);\n";
        $isolationBlock .= "    await pageB.goto(APP_URL + '#/signup', { waitUntil: 'networkidle' });\n";
        $isolationBlock .= "    await pageB.waitForTimeout(500);\n";
        $isolationBlock .= "    await pageB.fill('#auth-form #auth-identifier', TEST_EMAIL_B);\n";
        $isolationBlock .= "    await pageB.fill('#auth-form #auth-password', TEST_PASS);\n";
        $isolationBlock .= "    await pageB.click('#auth-form button[type=\"submit\"]');\n";
        $isolationBlock .= "    let bLoggedIn = false;\n";
        $isolationBlock .= "    try {\n";
        $isolationBlock .= "      await waitLoggedIn(pageB);\n";
        $isolationBlock .= "      bLoggedIn = true;\n";
        $isolationBlock .= "      [tokenB, userIdB] = await captureToken(pageB);\n";
        $isolationBlock .= "      await pageB.waitForTimeout(1500);\n";
        $isolationBlock .= "      const pageBBody = await pageB.textContent('body').catch(() => '');\n";
        $isolationBlock .= "      assert('User B cannot see User A records', !pageBBody.includes(" . $listAssertJson . "));\n";
        $isolationBlock .= "      // Direct-API policy checks: the UI may filter client-side, but the\n";
        $isolationBlock .= "      // API itself must refuse cross-user access to User A's record.\n";
        $isolationBlock .= "      if (tokenB && recAId && FIRST_TABLE) {\n";
        $isolationBlock .= "        log('Story: API blocks cross-user record access');\n";
        $isolationBlock .= "        const stGet = await apiGet(SB_API + '/data/' + PID + '/' + FIRST_TABLE + '/' + recAId, tokenB);\n";
        $isolationBlock .= "        assert('API refuses User B reading User A record directly', stGet === 403 || stGet === 404, 'GET status: ' + stGet);\n";
        $isolationBlock .= "        const stDel = await apiDelete(SB_API + '/data/' + PID + '/' + FIRST_TABLE + '/' + recAId, tokenB);\n";
        $isolationBlock .= "        assert('API refuses User B deleting User A record directly', stDel === 403 || stDel === 404, 'DELETE status: ' + stDel);\n";
        $isolationBlock .= "      }\n";
        $isolationBlock .= "    } catch (_) {\n";
        $isolationBlock .= "      assert('User B signup for isolation test', bLoggedIn, 'signup failed');\n";
        $isolationBlock .= "    }\n";
        $isolationBlock .= "    await pageB.close();\n";
        $isolationBlock .= "  }\n\n";
    }

    // ── Delete-all + empty-state block ─────────────────────────────────────────────
    $deleteAllBlock = '';
    if ($hasCrud) {
        $deleteAllBlock .= "  log('Story: Delete all records — app renders empty state without crash');\n";
        $deleteAllBlock .= "  await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $deleteAllBlock .= "  await page.waitForTimeout(1000);\n";
        $deleteAllBlock .= "  for (let _i = 0; _i < 20; _i++) {\n";
        $deleteAllBlock .= "    const itemLink = await page.\$('a[href*=\"" . $ipfx . "/\"]');\n";
        $deleteAllBlock .= "    if (!itemLink) break;\n";
        $deleteAllBlock .= "    await itemLink.click();\n";
        $deleteAllBlock .= "    await page.waitForTimeout(500);\n";
        $deleteAllBlock .= "    const delBtn = await page.\$('#delete-btn, button:has-text(\"Delete\")');\n";
        $deleteAllBlock .= "    if (!delBtn) { await page.goto(APP_URL, { waitUntil: 'networkidle' }); break; }\n";
        $deleteAllBlock .= "    page.once('dialog', d => d.accept());\n";
        $deleteAllBlock .= "    await delBtn.click();\n";
        $deleteAllBlock .= "    await page.waitForTimeout(1500);\n";
        $deleteAllBlock .= "    if (!page.url().startsWith(APP_URL.replace(/\\/+$/, ''))) {\n";
        $deleteAllBlock .= "      await page.goto(APP_URL, { waitUntil: 'networkidle' });\n";
        $deleteAllBlock .= "      await page.waitForTimeout(800);\n";
        $deleteAllBlock .= "    }\n";
        $deleteAllBlock .= "  }\n";
        $deleteAllBlock .= "  const crashErrors = pageErrors.filter(e =>\n";
        $deleteAllBlock .= "    e.toLowerCase().includes('typeerror') || e.toLowerCase().includes('uncaught'));\n";
        $deleteAllBlock .= "  assert('App renders without crash on empty list', crashErrors.length === 0, crashErrors.join(' | '));\n";
        $deleteAllBlock .= "  assert('New record button present in empty state', await page.\$('" . $newBtnSel . "') !== null);\n\n";
    }

    // ── Re-login block ─────────────────────────────────────────────────────────────
    $reloginBlock = '';
    if ($hasAuth) {
        $reloginBlock .= "  log('Story: User can re-login with same credentials');\n";
        $reloginBlock .= "  const logoutForRL = await page.\$('#nav-logout');\n";
        $reloginBlock .= "  if (logoutForRL) {\n";
        $reloginBlock .= "    const isHiddenRL = await logoutForRL.evaluate(el => el.classList.contains('hidden')).catch(() => true);\n";
        $reloginBlock .= "    if (!isHiddenRL) await logoutForRL.click();\n";
        $reloginBlock .= "    await page.waitForTimeout(1000);\n";
        $reloginBlock .= "  }\n";
        $reloginBlock .= "  await page.goto(APP_URL + '#/login', { waitUntil: 'networkidle' });\n";
        $reloginBlock .= "  await page.waitForTimeout(500);\n";
        $reloginBlock .= "  const loginFormRL = await page.\$('#auth-form');\n";
        $reloginBlock .= "  if (loginFormRL) {\n";
        $reloginBlock .= "    await page.fill('#auth-form #auth-identifier', TEST_EMAIL);\n";
        $reloginBlock .= "    await page.fill('#auth-form #auth-password', TEST_PASS);\n";
        $reloginBlock .= "    await page.click('#auth-form button[type=\"submit\"]');\n";
        $reloginBlock .= "  }\n";
        $reloginBlock .= "  let reLoggedIn = false;\n";
        $reloginBlock .= "  try { await waitLoggedIn(page); reLoggedIn = true; } catch (_) {}\n";
        $reloginBlock .= "  assert('User can re-login with same credentials', reLoggedIn);\n\n";
    }

    // ── Final logout block ─────────────────────────────────────────────────────────
    $logoutBlock = '';
    if ($hasAuth) {
        $logoutBlock .= "  log('Story: User can log out');\n";
        $logoutBlock .= "  const logoutEl = await page.\$('#nav-logout');\n";
        $logoutBlock .= "  assert('Logout button is present', logoutEl !== null);\n";
        $logoutBlock .= "  if (logoutEl) {\n";
        $logoutBlock .= "    await logoutEl.click();\n";
        $logoutBlock .= "    await page.waitForTimeout(1000);\n";
        $logoutBlock .= "    const onLoginRoute = page.url().includes('/login');\n";
        $logoutBlock .= "    const navHidden = await page.\$eval('#nav-logout', el => el.classList.contains('hidden')).catch(() => true);\n";
        $logoutBlock .= "    assert('After logout, login form is shown', onLoginRoute && navHidden);\n";
        $logoutBlock .= "  }\n";
    }

    // ── Cleanup block (always runs, outside try/catch) ─────────────────────────────
    $cleanupBlock = '';
    if ($hasAuth) {
        $cleanupBlock .= "// Cleanup test users via API\n";
        $cleanupBlock .= "try {\n";
        $cleanupBlock .= "  if (tokenA && userIdA) await apiDelete(SB_API + '/data/' + PID + '/' + AUTH_TABLE + '/' + userIdA, tokenA);\n";
        $cleanupBlock .= "  if (tokenB && userIdB) await apiDelete(SB_API + '/data/' + PID + '/' + AUTH_TABLE + '/' + userIdB, tokenB);\n";
        $cleanupBlock .= "} catch (_) {}\n\n";
    }

    $footer = <<<'JSEOF'
await page.screenshot({ path: '/tmp/sb_test_screenshot.png', fullPage: true }).catch(() => {});
await browser.close();

console.log('\n' + '═'.repeat(44));
console.log('Results: ' + passed + ' passed, ' + failed + ' failed');
console.log('═'.repeat(44));
console.log('__STORIES_JSON__' + JSON.stringify(stories));
process.exit(failed > 0 ? 1 : 0);
JSEOF;

    // AI-generated story tests run after the CRUD block (records exist, User A
    // is logged in). Wrapped in their own braces so any const/let the AI
    // declares can't collide with the harness's own declarations.
    $storySection = $storyBlock !== ''
        ? "  // ── AI-generated user-story tests ──\n  {\n" . $storyBlock . "\n  }\n\n"
        : '';

    return $header . "\n\n"
         . $constants . "\n"
         . $connect . "\n\n"
         . "try {\n"
         . $authBlock
         . $crudBlock
         . $storySection
         . $isolationBlock
         . $deleteAllBlock
         . $reloginBlock
         . $logoutBlock
         . "} catch (e) {\n"
         . "  // Record the crash as a story, not just failed++ — PHP's result parser\n"
         . "  // (ai_playwright_test_run) counts passed/failed from the stories array\n"
         . "  // alone, so a bare counter bump reported '0 passed, 0 failed' upstream\n"
         . "  // while the real error surfaced only as a detached raw string.\n"
         . "  if (!e.abort) assert('Test run aborted mid-way', false, 'Unexpected error: ' + e.message);\n"
         . "}\n\n"
         . $cleanupBlock
         . $footer;
}

function ai_playwright_test_run(string $script, array $config): array
{
    $nodeBin     = $config['NODE_BIN']           ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $nodeModules = $config['PLAYWRIGHT_MODULES'] ?? '/home/dxinethn/playwright-test/node_modules';

    // ESM bare-specifier resolution starts from the script file's directory, NOT NODE_PATH.
    // Write the script into the same directory as node_modules so `import 'playwright-core'`
    // resolves to the sibling node_modules/ folder.
    $playwrightDir = rtrim(dirname($nodeModules), '/');
    $tmpFile       = $playwrightDir . '/sb_test_' . getmypid() . '_' . time() . '.mjs';

    file_put_contents($tmpFile, $script);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv() ?: [], [
        'HOME' => dirname($playwrightDir),
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
    ]);

    $process = proc_open(
        escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpFile),
        $descriptors,
        $pipes,
        $playwrightDir,   // CWD = playwright-test dir for module resolution
        $env
    );

    if (!is_resource($process)) {
        @unlink($tmpFile);
        return ['stories' => [], 'passed' => 0, 'failed' => 1, 'error' => 'Failed to spawn Node process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($tmpFile);

    // Parse structured results from __STORIES_JSON__ marker
    $stories = [];
    $passed  = 0;
    $failed  = 0;

    $combinedOut = $stdout . "\n" . $stderr;
    if (preg_match('/__STORIES_JSON__(.+)$/m', $combinedOut, $m)) {
        $stories = json_decode($m[1], true) ?? [];
        foreach ($stories as $s) {
            if ($s['passed'] ?? false) $passed++; else $failed++;
        }
    }

    // A failure to even ESTABLISH the browser connection (Browserless quota,
    // a network blip) is a test-infrastructure problem, not evidence the app
    // is broken -- surfaced as its own field so callers can treat it
    // differently from a real story failure (e.g. not count it toward "still
    // failing after N auto-fix attempts"). See the JS connect block's own
    // comment for the live-observed case this fixes.
    $connectionError = null;
    if (preg_match('/__CONNECTION_ERROR__(.+)$/m', $combinedOut, $m)) {
        $decoded = json_decode($m[1], true);
        $connectionError = is_array($decoded) ? ($decoded['message'] ?? 'unknown connection error') : 'unknown connection error';
    }

    // Surface Node error lines when no stories were produced
    $nodeError = null;
    if (empty($stories) && trim($stderr) && !$connectionError) {
        $nodeError = trim(substr($stderr, 0, 500));
    }

    // Screenshot
    $screenshotB64 = null;
    $ssPath = '/tmp/sb_test_screenshot.png';
    if (file_exists($ssPath)) {
        $screenshotB64 = base64_encode((string)file_get_contents($ssPath));
        @unlink($ssPath);
    }

    return [
        'stories'          => $stories,
        'passed'           => $passed,
        'failed'           => $failed,
        'exit_code'        => $exitCode,
        'error'            => $nodeError,
        'connection_error' => $connectionError,
        'screenshot'       => $screenshotB64,
    ];
}

// One-shot Playwright script for the edit-agent's fetch_page tool: connect,
// navigate to a hash route (handling the SPA's client-side routing the way a
// real visitor's browser does — curl_site categorically cannot), snapshot the
// RENDERED page (post-JS text + visible interactive elements) and any console
// errors, then exit. Deliberately not the persistent bidirectional protocol
// ai_browser_agent_spawn() uses for the browser-test-agent (multi-turn
// click/fill interaction) — a single navigate+look needs none of that
// complexity, just a script that runs once and prints one result line.
function ai_fetch_page_script_generate(string $appUrl, string $token, string $path, bool $hasAuth): string
{
    $loginBlock = $hasAuth ? <<<'JSEOF'
    try {
      await page.goto(APP_URL + '#/signup', { waitUntil: 'networkidle', timeout: 15000 });
      await page.waitForTimeout(500);
      const idField = await page.$('#auth-form #auth-identifier');
      if (idField) {
        await page.fill('#auth-form #auth-identifier', TEST_EMAIL);
        await page.fill('#auth-form #auth-password', TEST_PASS);
        await page.click('#auth-form button[type="submit"]');
        await page.waitForFunction(
          () => { const el = document.querySelector('#nav-logout'); return el && !el.classList.contains('hidden'); },
          { timeout: 15000 }
        ).catch(() => {});
      }
    } catch (_) {}
JSEOF
        : '';

    $script = <<<'JSEOF'
import { chromium } from 'playwright-core';

const TOKEN = '__TOKEN__';
const APP_URL = '__APP_URL__';
const TARGET_PATH = '__PATH__';
const TEST_EMAIL = `pw-fetch-${Date.now()}@testmail.dev`;
const TEST_PASS = 'TestPass123!';

function sendResult(obj) {
  process.stdout.write('@@FETCH_RESULT@@' + JSON.stringify(obj) + '\n');
}

(async () => {
  let browser;
  try {
    try {
      browser = await chromium.connectOverCDP(`wss://chrome.browserless.io?token=${TOKEN}`);
    } catch (connErr) {
      // A distinct marker, NOT a generic {ok:false} -- same reasoning as the
      // deterministic test runner's __CONNECTION_ERROR__: a Browserless
      // quota/network failure here is a test-infrastructure problem, not
      // evidence of a real bug in the generated app, and callers (smoke_test's
      // finish() gate during frontend generation) need to tell the two apart
      // instead of blocking completion on something outside the app's code.
      sendResult({ ok: false, connection_error: connErr.message });
      return;
    }
    const page = await browser.newPage();
    page.setDefaultTimeout(15000);
    const consoleErrors = [];
    page.on('pageerror', (e) => consoleErrors.push(String((e && e.message) || e)));
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text().slice(0, 300)); });

__LOGIN_BLOCK__

    const cleanPath = TARGET_PATH.replace(/^#?\/?/, '/');
    const resp = await page.goto(APP_URL + '#' + cleanPath, { waitUntil: 'networkidle', timeout: 15000 }).catch(() => null);
    await page.waitForTimeout(700);

    const candidates = await page.$$('button, a, input, select, textarea, [role="button"]');
    const elements = [];
    for (const h of candidates) {
      let visible = false;
      try { visible = await h.isVisible(); } catch (_) { visible = false; }
      if (!visible) continue;
      try {
        const info = await h.evaluate(el => ({
          tag: el.tagName.toLowerCase(),
          text: (el.innerText || el.value || el.placeholder || '').trim().slice(0, 80),
        }));
        elements.push(info);
      } catch (_) {}
      if (elements.length >= 40) break;
    }

    let bodyText = '';
    try { bodyText = await page.evaluate(() => document.body.innerText); } catch (_) {}

    sendResult({
      ok: true,
      url: page.url(),
      http_status: resp ? resp.status() : null,
      bodyText: bodyText.slice(0, 1500),
      elements,
      console_errors: consoleErrors.slice(0, 10),
    });
  } catch (e) {
    sendResult({ ok: false, error: String((e && e.message) || e) });
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
})();
JSEOF;

    return str_replace(
        ['__TOKEN__', '__APP_URL__', '__PATH__', '__LOGIN_BLOCK__'],
        [$token, $appUrl, addslashes($path), $loginBlock],
        $script
    );
}

// Blocking one-shot runner for ai_fetch_page_script_generate()'s script —
// same proc_open mechanics as ai_playwright_test_run() (write to a temp .mjs
// file next to node_modules/ so ESM bare-specifier resolution finds
// 'playwright-core', run it, collect stdout/stderr) but parses the
// '@@FETCH_RESULT@@' marker this script emits instead of the test runner's
// '__STORIES_JSON__' one — kept as its own function rather than sharing code
// with ai_playwright_test_run() since the two return shapes and markers
// don't otherwise overlap.
function ai_fetch_page_run(string $script, array $config): array
{
    $nodeBin     = $config['NODE_BIN']           ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $nodeModules = $config['PLAYWRIGHT_MODULES'] ?? '/home/dxinethn/playwright-test/node_modules';
    $playwrightDir = rtrim(dirname($nodeModules), '/');
    $tmpFile       = $playwrightDir . '/sb_fetch_' . getmypid() . '_' . time() . '.mjs';

    file_put_contents($tmpFile, $script);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv() ?: [], [
        'HOME' => dirname($playwrightDir),
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
    ]);

    $process = proc_open(
        escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpFile),
        $descriptors,
        $pipes,
        $playwrightDir,
        $env
    );

    if (!is_resource($process)) {
        @unlink($tmpFile);
        return ['ok' => false, 'error' => 'Failed to spawn Node process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    @unlink($tmpFile);

    if (preg_match('/@@FETCH_RESULT@@(.+)$/m', $stdout . "\n" . $stderr, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) return $decoded;
    }
    return ['ok' => false, 'error' => trim(substr($stderr, 0, 500)) ?: 'no result produced'];
}

// Builds the persistent Node/Playwright process the agent loop drives one
// action at a time. Unlike ai_playwright_test_generate()'s script (assembled
// then run start-to-finish in one shot), this one sits in a request/response
// loop over stdin/stdout: one JSON command in, one JSON result out, prefixed
// with a marker so any stray console output from Playwright can't be mistaken
// for a protocol line. click/fill resolve purely against real element HANDLES
// captured by the most recent snapshot — never a selector string — so there
// is no selector-guessing surface at all, by construction.
function ai_browser_agent_script_generate(string $appUrl, string $token, bool $hasAuth): string
{
    // Login-first, signup-fallback rather than always signing up: this same
    // block runs again on every periodic page recycle (see __recycle__
    // below), not just once at startup, and TEST_EMAIL is the same constant
    // for the whole run — a second signup attempt with an email that
    // already exists silently fails (swallowed by the outer catch), leaving
    // every recycled page running unauthenticated and false-failing any
    // story that needs a login. Trying login first makes every call after
    // the very first one succeed the normal way instead.
    $loginBlock = $hasAuth ? <<<'JSEOF'
  let loggedIn = false;
  try {
    await page.goto(APP_URL + '#/login', { waitUntil: 'networkidle', timeout: 15000 });
    await page.waitForTimeout(500);
    const loginIdField = await page.$('#auth-form #auth-identifier');
    if (loginIdField) {
      await page.fill('#auth-form #auth-identifier', TEST_EMAIL);
      await page.fill('#auth-form #auth-password', TEST_PASS);
      await page.click('#auth-form button[type="submit"]');
      loggedIn = await page.waitForFunction(
        () => { const el = document.querySelector('#nav-logout'); return el && !el.classList.contains('hidden'); },
        { timeout: 8000 }
      ).then(() => true).catch(() => false);
    }
  } catch (_) {}
  if (!loggedIn) {
    try {
      await page.goto(APP_URL + '#/signup', { waitUntil: 'networkidle', timeout: 15000 });
      await page.waitForTimeout(500);
      const idField = await page.$('#auth-form #auth-identifier');
      if (idField) {
        await page.fill('#auth-form #auth-identifier', TEST_EMAIL);
        await page.fill('#auth-form #auth-password', TEST_PASS);
        await page.click('#auth-form button[type="submit"]');
        await page.waitForFunction(
          () => { const el = document.querySelector('#nav-logout'); return el && !el.classList.contains('hidden'); },
          { timeout: 15000 }
        ).catch(() => {});
      }
    } catch (_) {}
  }
JSEOF
        : '';

    $script = <<<'JSEOF'
import { chromium } from 'playwright-core';
import readline from 'readline';

const TOKEN = '__TOKEN__';
const APP_URL = '__APP_URL__';
const TEST_EMAIL = `pw-agent-${Date.now()}@testmail.dev`;
const TEST_PASS = 'TestPass123!';

let browser, page;
let lastHandles = [];
let lastPath = '/';

function sendResult(obj) {
  process.stdout.write('@@RESULT@@' + JSON.stringify(obj) + '\n');
}

function isDisconnectError(e) {
  const msg = String((e && e.message) || e || '').toLowerCase();
  return msg.includes('closed') || msg.includes('disconnected') || msg.includes('crashed');
}

async function connectAndLogin() {
  browser = await chromium.connectOverCDP(`wss://chrome.browserless.io?token=${TOKEN}`);
  page = await browser.newPage();
  page.setDefaultTimeout(15000);
__LOGIN_BLOCK__
}

async function doSnapshot() {
  const candidates = await page.$$('button, a, input, select, textarea, [role="button"]');
  const items = [];
  for (const h of candidates) {
    let visible = false;
    try { visible = await h.isVisible(); } catch (_) { visible = false; }
    if (!visible) continue;
    let info;
    try {
      info = await h.evaluate(el => ({
        tag: el.tagName.toLowerCase(),
        text: (el.innerText || el.value || el.placeholder || '').trim().slice(0, 80),
        type: el.type || null,
        checked: typeof el.checked === 'boolean' ? el.checked : null,
        disabled: !!el.disabled,
      }));
    } catch (_) { continue; }
    items.push({ handle: h, info });
    if (items.length >= 60) break;
  }
  lastHandles = items.map(it => it.handle);
  let bodyText = '';
  try { bodyText = await page.evaluate(() => document.body.innerText); } catch (_) {}
  return {
    url: page.url(),
    elements: items.map((it, i) => Object.assign({ index: i }, it.info)),
    bodyText: bodyText.slice(0, 1500),
  };
}

// Runs one command against the current page. Thrown errors propagate to the
// caller, which decides whether they're worth a reconnect-and-retry.
async function runCommand(cmd) {
  const tool = cmd.tool;
  const args = cmd.args || {};
  if (tool === 'navigate') {
    const path = String(args.path || '/').replace(/^#?\/?/, '/');
    lastPath = path;
    await page.goto(APP_URL + '#' + path, { waitUntil: 'networkidle', timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(500);
    return { tool, result: await doSnapshot() };
  }
  if (tool === 'snapshot') return { tool, result: await doSnapshot() };
  if (tool === 'click') {
    const h = lastHandles[args.index];
    if (!h) return { tool, error: 'no element at index ' + args.index + ' — call snapshot again' };
    await h.click({ timeout: 8000 });
    // Give an async handler (a fetch + re-render) a moment to at least start
    // before control returns — without this, a snapshot called immediately
    // after can catch the page mid-update, or a second click on what LOOKS
    // like the same still-live element can double-toggle it back before the
    // first click's own effect ever became visible.
    await page.waitForTimeout(300);
    // Bundling a fresh snapshot into the result (same as navigate already
    // does) instead of making the model spend a whole separate LLM turn on
    // an explicit snapshot call to see what its own action just did — that
    // was ~10s of pure model-decision latency per click/fill/wait for
    // nothing but permission to look at the page it just acted on.
    return { tool, result: Object.assign({ ok: true }, await doSnapshot()) };
  }
  if (tool === 'fill') {
    const h = lastHandles[args.index];
    if (!h) return { tool, error: 'no element at index ' + args.index + ' — call snapshot again' };
    await h.fill(String(args.value ?? ''), { timeout: 8000 });
    return { tool, result: Object.assign({ ok: true }, await doSnapshot()) };
  }
  if (tool === 'wait') {
    const ms = Math.min(Math.max(parseInt(args.ms, 10) || 500, 0), 3000);
    await page.waitForTimeout(ms);
    return { tool, result: Object.assign({ ok: true }, await doSnapshot()) };
  }
  return { tool, error: 'unknown tool: ' + tool };
}

// The remote Browserless session can die mid-test for reasons that have
// nothing to do with the app under test (a session/idle cap, a network
// blip) — a multi-story agent loop's real wall-clock time (every turn
// waits on a full model round-trip) can run well past what a single
// linear script ever needed. Treating that the same as "the feature is
// broken" would be exactly the false-negative superstition this whole
// agent replaced the old selector-guessing tests to avoid. One
// reconnect-and-retry attempt distinguishes a transient infra hiccup from
// a real failure; only a SECOND failure is reported to the model as-is.
async function handleCommand(cmd) {
  try {
    return await runCommand(cmd);
  } catch (e) {
    if (!isDisconnectError(e)) return { tool: cmd.tool, error: String((e && e.message) || e) };
    try {
      try { await browser.close(); } catch (_) {}
      await connectAndLogin();
      await page.goto(APP_URL + '#' + lastPath, { waitUntil: 'networkidle', timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(500);
      return await runCommand(cmd);
    } catch (e2) {
      return { tool: cmd.tool, error: 'browser session was interrupted (' + String((e && e.message) || e)
        + ') and could not be recovered — this is a test-infrastructure failure, not evidence the feature itself is broken' };
    }
  }
}

(async () => {
  try {
    await connectAndLogin();
  } catch (e) {
    sendResult({ tool: '__init__', error: 'Browser connect failed: ' + e.message });
    process.exit(1);
  }

  sendResult({ tool: '__init__', result: { ok: true } });

  const rl = readline.createInterface({ input: process.stdin });
  for await (const line of rl) {
    const trimmed = line.trim();
    if (!trimmed) continue;
    let cmd;
    try { cmd = JSON.parse(trimmed); } catch (_) { continue; }
    if (cmd.tool === '__shutdown__') {
      try { await browser.close(); } catch (_) {}
      process.exit(0);
    }
    if (cmd.tool === '__recycle__') {
      // Deliberate periodic close+reconnect (called by the PHP loop at each
      // story boundary, not just on error) -- a long-lived page/browser
      // accumulates enough local + remote state over many turns to risk
      // this account's per-process memory ceiling on a big multi-story run
      // (confirmed live: a 140-turn run died mid-test from exactly this).
      // Recycling bounds growth to roughly one story's worth of turns.
      try {
        try { await browser.close(); } catch (_) {}
        await connectAndLogin();
        await page.goto(APP_URL + '#' + lastPath, { waitUntil: 'networkidle', timeout: 15000 }).catch(() => {});
        await page.waitForTimeout(500);
        sendResult({ tool: '__recycle__', result: { ok: true } });
      } catch (e) {
        sendResult({ tool: '__recycle__', error: 'recycle failed: ' + String((e && e.message) || e) });
      }
      continue;
    }
    const res = await handleCommand(cmd);
    sendResult(res);
  }
  try { await browser.close(); } catch (_) {}
  process.exit(0);
})();
JSEOF;

    return str_replace(
        ['__TOKEN__', '__APP_URL__', '__LOGIN_BLOCK__'],
        [addslashes($token), addslashes($appUrl), $loginBlock],
        $script
    );
}

// Spawns the persistent agent script as its own OS process with stdin/stdout
// kept open as pipes across many send/receive turns (unlike
// ai_playwright_test_run's one-shot proc_open, which closes immediately after
// a single run). stderr goes to a file, not a pipe — an unread stderr pipe
// fills its OS buffer and blocks the child process, and nothing here ever
// drains it mid-run the way stdout is drained every turn.
function ai_browser_agent_spawn(string $script, array $config): ?array
{
    $nodeBin       = $config['NODE_BIN'] ?? '/opt/alt/alt-nodejs16/root/usr/bin/node';
    $nodeModules   = $config['PLAYWRIGHT_MODULES'] ?? '/home/dxinethn/playwright-test/node_modules';
    $playwrightDir = rtrim(dirname($nodeModules), '/');
    $tmpFile       = $playwrightDir . '/sb_agent_' . getmypid() . '_' . time() . '.mjs';
    file_put_contents($tmpFile, $script);

    $errFile     = sys_get_temp_dir() . '/sb_agent_stderr_' . getmypid() . '_' . time() . '.log';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'a']];
    $env = array_merge(getenv() ?: [], ['HOME' => dirname($playwrightDir), 'PATH' => '/usr/local/bin:/usr/bin:/bin']);

    $process = proc_open(escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpFile), $descriptors, $pipes, $playwrightDir, $env);
    if (!is_resource($process)) { @unlink($tmpFile); @unlink($errFile); return null; }
    stream_set_blocking($pipes[1], false);

    return ['proc' => $process, 'pipes' => $pipes, 'tmpFile' => $tmpFile, 'errFile' => $errFile];
}

// Sends one command and blocks (up to $timeoutSec) for the matching
// '@@RESULT@@'-prefixed response line — using stream_select rather than a
// blocking fgets() so a wedged/crashed Node process degrades to a clear
// timeout error instead of hanging this PHP process (and the job it's part
// of) forever.
// Pure read, no write — used both for the startup handshake (the process
// sends its __init__ result unprompted, before it ever reads stdin) and by
// ai_browser_agent_send() below, so a caller waiting on a response can never
// also be the one that desyncs the request/response pairing by writing an
// extra, uninvited command just to "wait" for something.
function ai_browser_agent_read(array $handles, int $timeoutSec): array
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) break;
        $read = [$handles['pipes'][1]];
        $write = null; $except = null;
        $sec  = (int)$remaining;
        $usec = (int)(($remaining - $sec) * 1_000_000);
        $n = @stream_select($read, $write, $except, $sec, $usec);
        if ($n === false || $n === 0) continue;
        $chunk = fgets($handles['pipes'][1]);
        if ($chunk === false) break; // EOF — the process died
        $pos = strpos($chunk, '@@RESULT@@');
        if ($pos === false) continue; // stray output — not a protocol line
        $decoded = json_decode(trim(substr($chunk, $pos + strlen('@@RESULT@@'))), true);
        if (is_array($decoded)) return $decoded;
    }
    return ['tool' => '', 'error' => 'timed out waiting for the browser agent to respond'];
}

function ai_browser_agent_send(array $handles, array $cmd, int $timeoutSec = AI_BROWSER_TEST_AGENT_TURN_TIMEOUT_SEC): array
{
    $tool = (string)($cmd['tool'] ?? '');
    if (@fwrite($handles['pipes'][0], json_encode($cmd) . "\n") === false) {
        return ['tool' => $tool, 'error' => 'failed to write to browser agent process'];
    }
    return ai_browser_agent_read($handles, $timeoutSec);
}

// Sends the shutdown command and gives the process a few seconds to close its
// browser connection cleanly before forcing it closed either way.
function ai_browser_agent_shutdown(array $handles): void
{
    @fwrite($handles['pipes'][0], json_encode(['tool' => '__shutdown__']) . "\n");
    @fclose($handles['pipes'][0]);
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $status = @proc_get_status($handles['proc']);
        if (!$status || !$status['running']) break;
        usleep(200_000);
    }
    @fclose($handles['pipes'][1]);
    @proc_close($handles['proc']);
    @unlink($handles['tmpFile']);
    @unlink($handles['errFile']);
}

// Friendly one-line label for the live progress card, mirroring
// ai_edit_agent_step_label's role for the code-editing agent.
function ai_browser_agent_step_label(string $tool, array $args): string
{
    return match ($tool) {
        'navigate'      => 'Navigating to ' . ($args['path'] ?? '/') . '…',
        'snapshot'       => 'Looking at the page…',
        'click'          => 'Clicking element ' . ($args['index'] ?? '?') . '…',
        'fill'           => 'Filling element ' . ($args['index'] ?? '?') . '…',
        'wait'           => 'Waiting…',
        'report_story'   => 'Recording: ' . ($args['label'] ?? '?') . '…',
        'finish'         => 'Finishing up…',
        default          => 'Working…',
    };
}

// Drives the live browser-testing agent to completion. Returns the same
// ['stories'=>[...], 'passed'=>N, 'failed'=>N] shape ai_playwright_test_run()
// returns, so ai_run_project_tests() can merge the two directly with no
// shape changes downstream.
function ai_run_browser_test_agent(
    object $client, array $stories, string $appUrl, string $browserlessToken, bool $hasAuth, callable $report
): array {
    $zeroUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    if (!$stories) return ['stories' => [], 'passed' => 0, 'failed' => 0, 'usage' => $zeroUsage];

    $script  = ai_browser_agent_script_generate($appUrl, $browserlessToken, $hasAuth);
    $handles = ai_browser_agent_spawn($script, \App::get('config'));
    if ($handles === null) {
        return ['stories' => [], 'passed' => 0, 'failed' => 1, 'error' => 'Failed to launch the browser testing agent', 'usage' => $zeroUsage];
    }

    // Wait for the __init__ handshake (browser connected + logged in) before
    // handing control to the model — a connect failure here is a hard stop,
    // not something for the agent to work around turn by turn. Pure read, no
    // write: the process sends this line on its own, before it ever reads
    // stdin, so writing a command here would just sit unread in its stdin
    // buffer and desync every response that follows.
    $init = ai_browser_agent_read($handles, 25);
    if (!empty($init['error']) && ($init['tool'] ?? '') === '__init__') {
        ai_browser_agent_shutdown($handles);
        // failed: 0, not 1 -- zero stories were actually exercised, so there
        // is no evidence the app is broken, only that this attempt couldn't
        // connect (Browserless quota, a network blip). connection_error lets
        // ai_run_test_and_autofix() tell that apart from a real failure
        // instead of counting it toward "still failing after N attempts".
        return ['stories' => [], 'passed' => 0, 'failed' => 0, 'error' => $init['error'], 'connection_error' => $init['error'], 'usage' => $zeroUsage];
    }

    $storiesText = implode("\n", array_map(fn($s, $i) => ($i + 1) . '. ' . $s, $stories, array_keys($stories)));
    $agentPrompt = AI_BROWSER_TEST_AGENT_SYSTEM_HEADER;
    $turnMsg = "User stories to verify, in order:\n{$storiesText}\n\nBegin with your first tool action.";
    $loopHistory = [];
    $recorded = [];
    $aiTrace  = [];
    $finished = false;
    $recentCalls = [];
    // Aggregated across every turn — a loop of up to AI_BROWSER_TEST_AGENT_MAX_TURNS
    // calls makes $client->getLastUsage() alone wildly undercount the real cost.
    $totalUsage = $zeroUsage;
    $consecutiveParseFailures = 0;

    // The whole run's turn budget is shared across every story with no per-
    // story limit, so a story the agent gets stuck on (e.g. a broken login
    // loop) can burn the entire budget and leave every later story completely
    // untested -- "turn budget exhausted before this story could be tested"
    // for story after story, even though most of them were never actually
    // attempted. A per-story ceiling guarantees every story gets a real
    // attempt: once a story's own share runs out, it's marked failed and the
    // agent is explicitly told to move on, instead of silently consuming
    // turns meant for stories still waiting.
    //
    // Recomputed every check (not fixed once upfront) from whatever turns and
    // stories are ACTUALLY left at that moment: a story that finishes well
    // under its share leaves the remaining turns to divide across fewer
    // remaining stories, so later ones automatically get more room instead of
    // a flat allotment that treats "logout button is present" the same as
    // "schedule a mentorship session".
    $turnsAtLastReport = 0;

    for ($turn = 1; $turn <= AI_BROWSER_TEST_AGENT_MAX_TURNS; $turn++) {
        $storiesLeft    = count($stories) - count($recorded);
        $turnsLeft      = AI_BROWSER_TEST_AGENT_MAX_TURNS - $turn + 1;
        $perStoryBudget = max(4, (int)floor($turnsLeft / max(1, $storiesLeft)));
        if (count($recorded) < count($stories) && ($turn - $turnsAtLastReport) > $perStoryBudget) {
            $stuckStory = $stories[count($recorded)];
            // 'skipped' (never actually observed either way) is a materially
            // different signal than a real, observed failure — conflating the
            // two as one flat 'passed: false' bucket is what let a Resolve
            // run burn its entire turn budget hunting for bugs in features
            // that were never actually shown broken, live-caught fixing
            // nothing for project 30. 'passed' stays false so every existing
            // consumer (pass/fail counts, dashboard summaries) keeps working
            // unchanged; 'skipped' is purely additive for anything that wants
            // to tell the two apart.
            $recorded[] = ['label' => $stuckStory, 'passed' => false, 'skipped' => true,
                'detail' => "Exceeded its share of the run's turn budget ({$perStoryBudget} turns) — moved on so later stories still get tested"];
            $turnsAtLastReport = $turn;
            $turnMsg = json_encode(['tool' => 'system', 'note' =>
                "\"{$stuckStory}\" took too long and was marked failed to protect the remaining stories' turn budget. Move on to the next untested story now."]);
            continue;
        }
        $_t0 = microtime(true);
        try {
            $action = $client->generateJsonWithHistory($agentPrompt, $loopHistory, $turnMsg);
        } catch (\Throwable $e) {
            if (ai_is_unrecoverable_provider_error($e->getMessage())) {
                throw new \RuntimeException('AI provider error during browser testing: ' . $e->getMessage());
            }
            $ms = (int)((microtime(true) - $_t0) * 1000);
            $aiTrace[] = ['stage' => 'browser_test_agent', 'system' => $agentPrompt, 'history' => [],
                'user_msg' => mb_strlen($turnMsg) > 3000 ? mb_substr($turnMsg, 0, 3000) : $turnMsg,
                'response' => ['error' => $e->getMessage()], 'tokens' => $client->getLastUsage(), 'ms' => $ms, 'retry' => true, 'error' => $e->getMessage()];
            if (ai_agent_is_rate_limited($e->getMessage())) {
                $report(['stage' => 'stories', 'status' => 'active', 'label' => 'Testing user stories…', 'detail' => 'Rate limited by the AI provider — waiting…']);
            } else {
                $report(['stage' => 'stories', 'status' => 'active', 'label' => 'Testing user stories…', 'detail' => 'Response was invalid, retrying…']);
                $turnMsg .= ai_agent_note_parse_failure($consecutiveParseFailures, $e->getMessage());
            }
            continue;
        }
        $consecutiveParseFailures = 0;
        $ms    = (int)((microtime(true) - $_t0) * 1000);
        $usage = $client->getLastUsage();
        foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($usage[$k] ?? 0);

        $tool = is_array($action) ? (string)($action['tool'] ?? '') : '';
        $args = is_array($action) && is_array($action['args'] ?? null) ? $action['args'] : [];

        $aiTrace[] = ['stage' => 'browser_test_agent', 'system' => $agentPrompt, 'history' => [],
            'user_msg' => mb_strlen($turnMsg) > 3000 ? mb_substr($turnMsg, 0, 3000) : $turnMsg,
            'response' => $action, 'tokens' => $usage, 'ms' => $ms, 'retry' => false];

        $report(['stage' => 'stories', 'status' => 'active', 'label' => 'Testing user stories…', 'detail' => ai_browser_agent_step_label($tool, $args)]);

        $loopHistory[] = ['role' => 'user', 'text' => $turnMsg];
        $loopHistory[] = ['role' => 'model', 'text' => ai_agent_history_action_json($action)];
        $loopHistory   = ai_agent_trim_history($loopHistory);

        if ($tool === 'report_story') {
            $label = (string)($args['label'] ?? 'Untitled story');
            $entry = [
                'label'  => $label,
                'passed' => (bool)($args['passed'] ?? false),
                'detail' => (string)($args['detail'] ?? ''),
            ];
            // The turn-budget timeout above can already have auto-failed this
            // exact story (moving $recorded forward so later stories still get
            // a turn) before the model, still mid-investigation, gets around
            // to reporting it for real. Replace that placeholder instead of
            // appending — the model's own specific result is strictly better
            // information than the generic timeout message, and appending
            // would double-count one story as two entries in the final tally.
            $dupIndex = null;
            foreach ($recorded as $i => $r) {
                if ($r['label'] === $label) { $dupIndex = $i; break; }
            }
            if ($dupIndex !== null) {
                $recorded[$dupIndex] = $entry;
            } else {
                $recorded[] = $entry;
            }
            $turnsAtLastReport = $turn;
            // Recycle the browser/page at this natural story boundary — see
            // the Node script's __recycle__ handler's own comment for why.
            // Best-effort: if it fails, the next real command's existing
            // disconnect-and-retry path recovers anyway.
            ai_browser_agent_send($handles, ['tool' => '__recycle__']);
            $turnMsg = json_encode(['tool' => 'report_story', 'result' => ['ok' => true, 'recorded' => count($recorded), 'of' => count($stories)]]);
            continue;
        }

        if ($tool === 'finish') {
            if (count($recorded) < count($stories)) {
                $turnMsg = json_encode(['tool' => 'finish', 'error' =>
                    'Only ' . count($recorded) . ' of ' . count($stories) . ' stories have been reported — continue testing the rest before finishing.']);
                continue;
            }
            $finished = true;
            break;
        }

        if (ai_agent_detect_stuck_repeat($recentCalls, $tool, $args)) {
            $turnMsg = json_encode(['tool' => $tool, 'error' =>
                'You have called this exact action with these exact arguments several times in a row with no ' .
                'different result to show for it. Repeating it again will not work either — try a genuinely ' .
                'different action, or if this story truly cannot be verified, report_story it false with what ' .
                'you actually observed.']);
            continue;
        }

        $result = ai_browser_agent_send($handles, ['tool' => $tool, 'args' => $args]);
        $turnMsg = json_encode($result);
    }

    if (!$finished) {
        // Turn budget exhausted — anything not yet reported is an honest,
        // visible gap rather than a silently dropped story.
        $reportedLabels = array_column($recorded, 'label');
        foreach ($stories as $s) {
            $alreadyCovered = false;
            foreach ($reportedLabels as $rl) if (str_contains($rl, $s) || str_contains($s, $rl)) { $alreadyCovered = true; break; }
            if (!$alreadyCovered) {
                $recorded[] = ['label' => $s, 'passed' => false, 'skipped' => true, 'detail' => 'Turn budget exhausted before this story could be tested'];
            }
        }
    }

    ai_browser_agent_shutdown($handles);

    $passed = count(array_filter($recorded, fn($s) => $s['passed']));
    $failed = count($recorded) - $passed;
    return ['stories' => $recorded, 'passed' => $passed, 'failed' => $failed, 'aiTrace' => $aiTrace, 'usage' => $totalUsage];
}

// Pull the flat list of user-story labels out of a saved project_requirements
// intent (the Review flow's nested actors[].stories[] shape).
function ai_extract_saved_stories(?array $requirements): array
{
    $stories = [];
    foreach (($requirements['actors'] ?? []) as $actor) {
        if (!is_array($actor)) continue;
        foreach (($actor['stories'] ?? []) as $s) {
            $label = is_string($s) ? $s : (string)($s['title'] ?? '');
            if (trim($label) !== '') $stories[] = trim($label);
        }
    }
    return array_values(array_unique($stories));
}

// Fallback for projects that never went through the Review flow (no saved
// requirements): ask the model to infer user stories from the schema + the
// deployed frontend, so any project still gets story-driven tests.
function ai_infer_stories(object $client, array $schema, string $indexHtml): array
{
    $userMsg = "Schema:\n" . ai_schema_to_context($schema)
             . "\n\nDeployed index.html:\n" . mb_substr($indexHtml, 0, 8000);
    $res = $client->generateJson(AI_INFER_STORIES_PROMPT, $userMsg);
    $out = [];
    foreach ((array)($res['stories'] ?? []) as $s) {
        if (is_string($s) && trim($s) !== '') $out[] = trim($s);
    }
    return array_values(array_unique($out));
}

// A test run creates real rows through the live app itself -- the fixed
// TEST_EMAIL signup, plus whatever data a story's own interactions leave
// behind (an enrollment, a project application, a mentorship booking,
// anything a story exercises with agent-invented content that has no fixed,
// recognizable pattern to match on afterward). None of that goes through the
// PHP seed-insert path, so project_seed_rows never learns about it and Clear
// Seed Data has no way to find it. Snapshotting each table's MAX(id) before
// the run and tracking whatever's newer afterward catches all of it, in any
// table, regardless of what the row actually looks like.
function ai_snapshot_table_high_marks(int $projectId, \SupaBein\Catalog $catalog): array
{
    $pdo   = \App::get('db');
    $marks = [];
    foreach ($catalog->listTables($projectId) as $t) {
        try {
            $marks[$t['table_name']] = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$t['physical_name']}`")->fetchColumn();
        } catch (\Throwable $e) { /* non-fatal -- table may be mid-migration */ }
    }
    return $marks;
}

function ai_track_new_rows_since(int $projectId, \SupaBein\Catalog $catalog, array $beforeMarks): void
{
    $pdo = \App::get('db');
    foreach ($catalog->listTables($projectId) as $t) {
        $before = $beforeMarks[$t['table_name']] ?? null;
        if ($before === null) continue; // wasn't there for the "before" snapshot -- nothing to diff against
        try {
            $stmt = $pdo->prepare("SELECT id FROM `{$t['physical_name']}` WHERE id > ?");
            $stmt->execute([$before]);
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids) ai_track_seed_rows($pdo, $projectId, $t['table_name'], $ids);
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}

/**
 * @param array|null $storiesOverride When provided, skips deriving the full
 *   story set (ai_extract_saved_stories()/ai_infer_stories()) and tests only
 *   these specific stories instead. Used by ai_run_test_and_autofix() to
 *   re-verify just the stories that were failing after a narrowly-scoped fix
 *   (one that only touched an isolated feature file, not anything shared)
 *   instead of unconditionally re-running the entire suite -- real sequential
 *   browser time for stories nowhere near the changed code isn't buying any
 *   extra safety margin. The deterministic auth/CRUD/isolation checks below
 *   always run regardless -- those are fast and catch broad regressions a
 *   scoped story list wouldn't.
 */
function ai_run_project_tests(int $projectId, int $userId, \SupaBein\Catalog $catalog, array $config, callable $report, ?object $client = null, ?array $storiesOverride = null): array
{
    $project = $catalog->getProjectById($projectId, $userId);
    if (!$project) throw new \RuntimeException('Project not found');

    $sites = $catalog->listSites($projectId);
    if (!$sites) throw new \RuntimeException('No deployed site found — build the project first');

    $preTestMarks = ai_snapshot_table_high_marks($projectId, $catalog);

    $site = $sites[0];
    if ($site['staging_deploy_id'] ?? null) {
        $target = 'staging';
    } elseif ($site['current_deploy_id'] ?? null) {
        $target = 'current';
    } else {
        throw new \RuntimeException('No deploy found — build or edit the project first');
    }

    $browserlessToken = $config['BROWSERLESS_TOKEN'] ?? '';
    if (!$browserlessToken) throw new \RuntimeException('Browserless token not configured (add BROWSERLESS_TOKEN to config/secrets.php)');

    $report(['stage' => 'script', 'status' => 'start', 'label' => 'Preparing test script…']);
    $siteId    = (int)$site['id'];
    $sitesPath = rtrim($config['SITES_PATH'], '/');
    $appUrl    = rtrim($config['API_BASE_URL'], '/') . "/sites/s{$siteId}/{$target}/";
    $indexPath = "{$sitesPath}/s{$siteId}/{$target}/index.html";
    $indexHtml = file_exists($indexPath) ? (string)file_get_contents($indexPath) : '';

    $schema = ai_schema_from_db($projectId, $catalog);
    $report(['stage' => 'script', 'status' => 'done', 'label' => 'Test script ready']);

    // Generic auth/CRUD/isolation/logout tests, deterministic and unchanged —
    // these already target conventions AI_FRONTEND_RULES mandates (name="col",
    // #auth-form ids, etc.), so they don't share the "distinctive feature"
    // story tests' guessing problem below.
    $script = ai_playwright_test_generate($appUrl, $browserlessToken, $schema, $indexHtml, $projectId, '');

    $report(['stage' => 'run', 'status' => 'start', 'label' => 'Running browser tests…']);
    $result = ai_playwright_test_run($script, $config);
    $report(['stage' => 'run', 'status' => 'done',
             'label'  => 'Tests finished',
             'detail' => ($result['passed'] ?? 0) . ' passed, ' . ($result['failed'] ?? 0) . ' failed']);

    // Story-driven tests: the user stories captured in the Review flow (saved
    // to project_requirements) become their own live, agent-driven browser
    // checks. If the project never went through Review, infer stories from
    // the schema + the deployed frontend instead, so every project still gets
    // story tests. Best effort throughout — any failure here just means the
    // deterministic tests above stand alone.
    $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    if ($client) {
        $report(['stage' => 'stories', 'status' => 'start', 'label' => 'Testing user stories…']);
        try {
            if ($storiesOverride) {
                $stories = $storiesOverride;
                $source  = 'scoped re-test';
            } else {
                $stories = ai_extract_saved_stories($catalog->getProjectRequirements($projectId));
                $source  = 'saved';
                if (!$stories) {
                    $stories = ai_infer_stories($client, $schema, $indexHtml);
                    $source  = 'inferred';
                    $usage   = $client->getLastUsage();
                    foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($usage[$k] ?? 0);
                }
            }
            $authInfo   = ai_detect_auth($schema);
            $agentResult = ai_run_browser_test_agent($client, $stories, $appUrl, $browserlessToken, !empty($authInfo['table']), $report);
            foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($agentResult['usage'][$k] ?? 0);
            if (!empty($agentResult['stories'])) {
                $result['stories'] = array_merge($result['stories'] ?? [], $agentResult['stories']);
                $result['passed']  = ($result['passed'] ?? 0) + $agentResult['passed'];
                $result['failed']  = ($result['failed'] ?? 0) + $agentResult['failed'];
            }
            // Neither side's connection_error should shadow the other's --
            // whichever stage actually hit one (the deterministic run above,
            // or this story agent) needs to survive the merge so the caller
            // knows this test pass was inconclusive, not that the app failed.
            if (!empty($agentResult['connection_error'])) {
                $result['connection_error'] = $agentResult['connection_error'];
            }
            if (!empty($agentResult['aiTrace'])) {
                $result['aiTrace'] = array_merge($result['aiTrace'] ?? [], $agentResult['aiTrace']);
            }
            $report(['stage' => 'stories', 'status' => 'done',
                     'label'  => $stories ? 'User stories tested' : 'No user stories',
                     'detail' => $stories
                         ? count($stories) . ' user stories tested (' . $source . ') — '
                             . $agentResult['passed'] . ' passed, ' . $agentResult['failed'] . ' failed'
                         : (!empty($agentResult['error']) ? $agentResult['error'] : 'no stories to test')]);
        } catch (\Throwable $e) {
            $report(['stage' => 'stories', 'status' => 'done', 'label' => 'User-story testing skipped', 'detail' => $e->getMessage()]);
        }
    }

    sb_log('ai_test', !empty($result['error']) ? 'Failed: ' . $result['error'] : 'Complete', [
        'project_id' => $projectId,
        'target'     => $target,
        'passed'     => $result['passed'] ?? null,
        'failed'     => $result['failed'] ?? null,
    ]);

    // Validate the same deployed files being tested, so one job produces one
    // combined picture of the app's health instead of two separate checks.
    $validation = [];
    $report(['stage' => 'validate', 'status' => 'start', 'label' => 'Checking for mismatches…']);
    try {
        $frontendFiles = ai_read_full_frontend_files($config, $catalog, $projectId, $target);
        $validation    = ai_validator_check_project($schema, $frontendFiles);
        if ($client && array_filter($validation, fn($f) => $f['severity'] === 'error')) {
            $validation = ai_validator_explain_findings($validation, $client);
            $usage = $client->getLastUsage();
            foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($usage[$k] ?? 0);
        }
        $errCount  = count(array_filter($validation, fn($f) => $f['severity'] === 'error'));
        $warnCount = count(array_filter($validation, fn($f) => $f['severity'] === 'warning'));
        $report(['stage' => 'validate', 'status' => 'done',
                 'label'  => $validation ? 'Validation found issues' : 'No issues found',
                 'detail' => $validation ? "{$errCount} error(s), {$warnCount} warning(s)" : '']);
    } catch (\Throwable $e) {
        $report(['stage' => 'validate', 'status' => 'done', 'label' => 'Validation skipped', 'detail' => $e->getMessage()]);
    }

    ai_track_new_rows_since($projectId, $catalog, $preTestMarks);

    return array_merge($result, ['target' => $target, 'validation' => $validation, 'usage' => $totalUsage]);
}

// A connection-level failure (Browserless quota, a network blip) means the
// test pass just run verified NOTHING -- retrying the SAME test a couple of
// times is cheap and often just waits out a transient blip, unlike paying
// for a whole new auto-fix edit attempt to "fix" a bug that may not even
// exist. See ai_playwright_test_run()'s own connection_error field and its
// doc comment for the live-observed case this exists to stop.
function ai_run_project_tests_with_connection_retry(int $projectId, int $userId, \SupaBein\Catalog $catalog, array $config, callable $report, ?object $client, int $maxAttempts = 3, ?array $storiesOverride = null): array
{
    $result = [];
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = ai_run_project_tests($projectId, $userId, $catalog, $config, $report, $client, $storiesOverride);
        if (empty($result['connection_error'])) return $result;
        if ($attempt < $maxAttempts) {
            $report(['stage' => 'stories', 'status' => 'active', 'label' => 'Testing user stories…',
                'detail' => 'Could not connect to the test browser — retrying…']);
            sleep(3);
        }
    }
    return $result; // still has connection_error set -- caller must check for it
}

// Runs the test suite, and if it reports failing user stories, feeds the
// SPECIFIC failures (story label + what was actually observed, not just
// "something failed") back in as an edit request, applies and deploys the
// fix to STAGING ONLY (identical to how every normal edit apply already
// works — the user still publishes to live explicitly, autofix never does
// that for them), then re-tests. Always goes through the edit agent, never
// the build agent — once a project exists, fixing it is an edit no matter
// whether the failure surfaced right after the very first build or much
// later. Stops on a clean pass, the hard cap above, or the failing-story
// set coming back byte-identical to the previous attempt's — a fix that
// visibly changed nothing is not worth paying for a second time.
function ai_run_test_and_autofix(int $projectId, int $userId, \SupaBein\Catalog $catalog, array $config, callable $report, object $client): array
{
    $fixAttempts = [];
    $prevFailingSignature = null;
    // ai_run_project_tests()'s own 'usage' only ever covers that ONE call —
    // each retest below overwrites it on $result, so the running total
    // across every test pass AND every autofix edit in between has to be
    // tracked separately here, not read back off $result at the end.
    $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    $addUsage = function (?array $usage) use (&$totalUsage) {
        foreach ($totalUsage as $k => $v) $totalUsage[$k] = $v + (int)($usage[$k] ?? 0);
    };

    $result = ai_run_project_tests_with_connection_retry($projectId, $userId, $catalog, $config, $report, $client);
    $addUsage($result['usage'] ?? null);
    if (!empty($result['connection_error'])) {
        // Never entered the fix loop at all -- there's no signal here to act
        // on, good or bad. Distinct from autofix_gave_up (which means real
        // failures were found and repair was attempted) and distinct from a
        // clean pass (which means stories were actually verified).
        $result['test_infra_unavailable'] = true;
        $result['autofix_attempts'] = [];
        $result['autofix_gave_up']  = false;
        $result['usage'] = $totalUsage;
        return $result;
    }

    for ($fixAttempt = 1; $fixAttempt <= AI_TEST_AUTOFIX_MAX_ATTEMPTS; $fixAttempt++) {
        $failingStories = array_values(array_filter($result['stories'] ?? [], fn($s) => empty($s['passed'])));
        if (!$failingStories) break; // clean — nothing to fix

        $signature = implode('|', array_map(
            fn($s) => ($s['label'] ?? '') . ':' . ($s['detail'] ?? ''),
            $failingStories
        ));
        if ($signature === $prevFailingSignature) {
            $result['autofix_stalled'] = true;
            break; // the previous fix attempt visibly changed nothing — don't repeat it
        }
        $prevFailingSignature = $signature;

        $report(['stage' => 'autofix', 'status' => 'active', 'label' =>
            "Auto-fix attempt {$fixAttempt}/" . AI_TEST_AUTOFIX_MAX_ATTEMPTS . ': fixing '
            . count($failingStories) . ' failing ' . (count($failingStories) === 1 ? 'story' : 'stories') . '…']);

        $fixPrompt = "The following user stories failed real browser testing against the deployed app. Fix the "
            . "actual underlying problem behind each one:\n\n" . implode("\n\n", array_map(
                fn($s) => '- "' . ($s['label'] ?? 'Untitled story') . '": ' . ($s['detail'] ?? 'no detail captured'),
                $failingStories
            ));

        $editResult = ai_run_edit_generation($projectId, $fixPrompt, [], $client, $catalog, $config, $report, true);
        $plan       = $editResult['plan'] ?? [];
        $addUsage($editResult['usage'] ?? null);

        $deltaError = ai_validate_delta($plan, ai_schema_from_db($projectId, $catalog));
        if ($deltaError !== null) {
            // Nothing safe to apply — stop rather than deploy a broken
            // change or loop again on a generation that's already failing.
            $result['autofix_error'] = "Auto-fix attempt {$fixAttempt} produced an invalid change: {$deltaError}";
            break;
        }

        ai_execute_edit($plan, $projectId, $userId);

        $project = $catalog->getProjectById($projectId, $userId);
        $sites   = $catalog->listSites($projectId);
        if ($project && $sites && !empty($plan['frontend']['files'])) {
            $updatedSchema = ai_schema_from_db($projectId, $catalog);
            // Staging only — same deploy call and same $publishLive=false
            // every normal edit apply already uses.
            ai_deploy_files($config, $catalog, (int)$sites[0]['id'], $project,
                             $plan['frontend']['files'], true, false, ai_detect_auth($updatedSchema));
        }

        $fixAttempts[] = [
            'attempt'         => $fixAttempt,
            'failing_stories' => array_map(fn($s) => $s['label'] ?? '', $failingStories),
            'fix_summary'     => [
                'add_tables'      => count($plan['add_tables'] ?? []),
                'add_columns'     => count($plan['add_columns'] ?? []),
                'update_policies' => count($plan['update_policies'] ?? []),
                'frontend_files'  => count($plan['frontend']['files'] ?? []),
            ],
        ];

        // Blast-radius check: a fix confined to isolated feature files (and
        // no schema change) can't plausibly affect stories that weren't
        // already failing, so only re-verify those instead of paying for the
        // full suite again -- real sequential browser time for stories
        // nowhere near the changed code isn't buying extra safety margin.
        // Any schema change or any touched file outside features/ (core/*,
        // index.html, a new top-level file) falls back to the full retest,
        // since those ARE plausibly shared by everything.
        $changedPaths = array_map(fn($f) => $f['path'] ?? '', $plan['frontend']['files'] ?? []);
        $isFeatureScoped = empty($plan['add_tables']) && empty($plan['add_columns']) && empty($plan['update_policies'])
            && $changedPaths && !array_filter($changedPaths, fn($p) => !str_starts_with($p, 'features/'));
        $retestStories = $isFeatureScoped ? array_map(fn($s) => $s['label'] ?? '', $failingStories) : null;

        $report(['stage' => 'autofix', 'status' => 'active', 'label' => "Auto-fix attempt {$fixAttempt}: re-testing…"
            . ($isFeatureScoped ? ' (' . count($retestStories) . ' affected stor' . (count($retestStories) === 1 ? 'y' : 'ies') . ')' : '')]);
        $previousStories = $result['stories'] ?? [];
        $result = ai_run_project_tests_with_connection_retry($projectId, $userId, $catalog, $config, $report, $client, 3, $retestStories);
        $addUsage($result['usage'] ?? null);
        if (!empty($result['connection_error'])) {
            // The FIX just applied was never actually verified -- stop here
            // rather than let the loop treat an empty (unverified) stories
            // list as "clean" on the next iteration, or count this against
            // autofix_gave_up as if the fix were confirmed still broken.
            $result['test_infra_unavailable'] = true;
            break;
        }
        if ($isFeatureScoped) {
            // A scoped retest only re-verified the previously-failing
            // stories -- merge those updated results back into the last
            // full picture instead of letting the scoped subset stand in
            // for the whole suite (which would understate "N passed" in the
            // final report for stories that were confirmed fine earlier and
            // simply weren't in blast radius of this fix).
            $mergedStories = $previousStories;
            foreach ($result['stories'] as $updated) {
                $idx = null;
                foreach ($mergedStories as $i => $old) {
                    if (($old['label'] ?? null) === ($updated['label'] ?? null)) { $idx = $i; break; }
                }
                if ($idx !== null) $mergedStories[$idx] = $updated; else $mergedStories[] = $updated;
            }
            $result['stories'] = $mergedStories;
            $result['passed']  = count(array_filter($mergedStories, fn($s) => !empty($s['passed'])));
            $result['failed']  = count($mergedStories) - $result['passed'];
        }
    }

    $result['autofix_attempts'] = $fixAttempts;
    $result['autofix_gave_up']  = empty($result['test_infra_unavailable'])
        && (bool)array_filter($result['stories'] ?? [], fn($s) => empty($s['passed']));
    $result['usage'] = $totalUsage;
    return $result;
}
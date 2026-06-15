'use strict';

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

// ─── API ─────────────────────────────────────────────────────────────────────

const Api = (() => {
  // Derive API base from current location
  const BASE = (() => {
    const parts = window.location.pathname.split('/');
    // Remove 'dashboard/index.html' or similar to get to /api
    const base = window.location.origin + '/api';
    return base;
  })();

  async function request(method, path, data, isFormData = false) {
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

    const res = await fetch(BASE + path, { method, headers, body });

    if (res.status === 401) {
      Auth.clear();
      Router.navigate('/login');
      return null;
    }

    const json = await res.json().catch(() => ({}));

    if (!res.ok) {
      const err = new ApiError(json.error || 'Request failed', res.status);
      console.error('[SupaBein] API error', { method, path, status: res.status, message: err.message });
      throw err;
    }

    return json;
  }

  class ApiError extends Error {
    constructor(msg, status) {
      super(msg);
      this.status = status;
    }
  }

  return {
    BASE,
    get:    (path)       => request('GET',    path),
    post:   (path, data) => request('POST',   path, data),
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
    if (typeof c === 'string') e.appendChild(document.createTextNode(c));
    else if (c) e.appendChild(c);
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

function fmtDate(d) {
  return d ? new Date(d).toLocaleString() : '—';
}

function requireAuth() {
  if (!Auth.isLoggedIn()) {
    Router.navigate('/login');
    return false;
  }
  return true;
}

// ─── Layout ──────────────────────────────────────────────────────────────────

function renderLayout(projectId, activeTab, content) {
  const user = Auth.getUser();
  const base = projectId ? `/projects/${projectId}` : '';

  const sidebar = el('nav', { class: 'sidebar' },
    el('div', { class: 'sidebar-logo' }, 'SupaBein'),
    el('a', { href: '#/projects' }, 'Projects'),
    ...(projectId ? [
      el('a', { href: `#/projects/${projectId}` }, '← Project'),
      el('a', { href: `#/projects/${projectId}/tables`, class: activeTab === 'tables' ? 'active' : '' }, 'Tables'),
      el('a', { href: `#/projects/${projectId}/sites`, class: activeTab === 'sites' ? 'active' : '' }, 'Deploy'),
      el('a', { href: `#/projects/${projectId}/api`, class: activeTab === 'api' ? 'active' : '' }, 'API'),
    ] : []),
    el('div', { style: 'flex:1' }),
    el('a', { href: '#/logout', style: 'margin-top:auto' }, user?.email || 'Logout')
  );

  const wrap = el('div', { class: 'layout' }, sidebar, el('main', { class: 'main', id: 'content' }, ...content));
  const app = document.getElementById('app');
  app.innerHTML = '';
  app.appendChild(wrap);
}

// ─── Pages ───────────────────────────────────────────────────────────────────

// Login
function renderLogin() {
  const form = h(`
    <div class="auth-wrap">
      <div class="auth-box card">
        <div class="auth-logo">SupaBein</div>
        <div class="auth-sub">Sign in to your account</div>
        <div class="form-group"><label>Email</label><input type="email" id="email" placeholder="you@example.com"></div>
        <div class="form-group"><label>Password</label><input type="password" id="password"></div>
        <button class="btn btn-primary w-full" id="submit">Sign In</button>
        <div class="mt-3 text-sm text-muted" style="text-align:center">
          No account? <a href="#/signup">Sign up</a>
        </div>
      </div>
    </div>
  `);

  form.querySelector('#submit').addEventListener('click', async () => {
    const email    = form.querySelector('#email').value;
    const password = form.querySelector('#password').value;
    try {
      const res = await Api.post('/v1/auth/login', { email, password });
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      showAlert(form.querySelector('.auth-box'), e.message);
    }
  });

  setApp(form);
}

// Signup
function renderSignup() {
  const form = h(`
    <div class="auth-wrap">
      <div class="auth-box card">
        <div class="auth-logo">SupaBein</div>
        <div class="auth-sub">Create your account</div>
        <div class="form-group"><label>Email</label><input type="email" id="email" placeholder="you@example.com"></div>
        <div class="form-group"><label>Password</label><input type="password" id="password" placeholder="At least 8 characters"></div>
        <button class="btn btn-primary w-full" id="submit">Create Account</button>
        <div class="mt-3 text-sm text-muted" style="text-align:center">
          Have an account? <a href="#/login">Sign in</a>
        </div>
      </div>
    </div>
  `);

  form.querySelector('#submit').addEventListener('click', async () => {
    const email    = form.querySelector('#email').value;
    const password = form.querySelector('#password').value;

    console.log('[SupaBein] Signup button clicked', { email, passwordLen: password.length });
    console.log('[SupaBein] POST URL:', Api.BASE + '/v1/auth/signup');

    try {
      console.log('[SupaBein] Sending signup request...');
      const res = await Api.post('/v1/auth/signup', { email, password });
      console.log('[SupaBein] Signup success', res);
      Auth.setToken(res.token);
      Router.navigate('/projects');
    } catch (e) {
      console.error('[SupaBein] Signup failed', { status: e.status, message: e.message });
      showAlert(form.querySelector('.auth-box'), e.message);
    }
  });

  setApp(form);
}

// Projects list
async function renderProjects() {
  if (!requireAuth()) return;

  renderLayout(null, '', [el('div', { class: 'page-header' },
    el('h1', { class: 'page-title' }, 'Projects'),
    el('button', { class: 'btn btn-primary', id: 'new-project' }, '+ New Project')
  ), el('div', { id: 'project-list' }, 'Loading...')]);

  document.getElementById('new-project').addEventListener('click', () => showNewProjectModal());

  try {
    const projects = await Api.get('/v1/projects');
    const list = document.getElementById('project-list');

    if (!projects.length) {
      list.innerHTML = '<div class="text-muted">No projects yet. Create one to get started.</div>';
      return;
    }

    const table = el('table', { class: 'data-table' },
      el('thead', {}, el('tr', {},
        el('th', {}, 'Name'), el('th', {}, 'Created'), el('th', {}, '')
      )),
      el('tbody', {}, ...projects.map(p =>
        el('tr', {},
          el('td', {}, el('a', { href: `#/projects/${p.id}` }, p.name)),
          el('td', { class: 'text-muted text-sm' }, fmtDate(p.created_at)),
          el('td', {},
            el('button', {
              class: 'btn btn-sm btn-danger',
              onClick: async () => {
                if (!confirm(`Delete project "${p.name}"? This cannot be undone.`)) return;
                await Api.delete(`/v1/projects/${p.id}`);
                renderProjects();
              }
            }, 'Delete')
          )
        )
      ))
    );

    list.innerHTML = '';
    list.appendChild(table);
  } catch (e) {
    document.getElementById('project-list').innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
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

  try {
    const project = await Api.get(`/v1/projects/${id}`);
    renderLayout(id, '', [
      el('div', { class: 'page-header' },
        el('h1', { class: 'page-title' }, project.name)
      ),
      el('div', { class: 'flex gap-2' },
        el('a', { class: 'btn btn-primary', href: `#/projects/${id}/tables` }, 'Tables'),
        el('a', { class: 'btn btn-secondary', href: `#/projects/${id}/sites` }, 'Deploy')
      ),
      el('div', { class: 'card mt-3' },
        el('div', { class: 'card-title' }, 'Project Info'),
        el('div', { class: 'text-sm text-muted' }, `ID: ${project.id}`),
        el('div', { class: 'text-sm text-muted mt-1' }, `Created: ${fmtDate(project.created_at)}`)
      )
    ]);
  } catch (e) {
    setApp(`<div class="alert alert-error">${e.message}</div>`);
  }
}

// Tables list
async function renderTables({ id }) {
  if (!requireAuth()) return;

  renderLayout(id, 'tables', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, 'Tables'),
      el('button', { class: 'btn btn-primary', id: 'new-table' }, '+ New Table')
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

  try {
    const [cols, res] = await Promise.all([
      Api.get(`/v1/projects/${projectId}/tables/${tableName}/columns`),
      Api.get(`/v1/data/${projectId}/${tableName}?limit=50`)
    ]);

    const allCols = [{ col_name: 'id' }, { col_name: 'created_at' }, ...cols];
    const rows    = res.data || [];

    const insertForm = h(`
      <div class="card">
        <div class="card-title">Insert Row</div>
        ${cols.map(c => `<div class="form-group"><label>${c.col_name} <span class="text-muted">(${c.data_type})</span></label><input type="text" data-col="${c.col_name}"></div>`).join('')}
        <button class="btn btn-primary btn-sm" id="insert-row">Insert</button>
      </div>
    `);

    const insertFormEl = insertForm.firstElementChild;
    insertFormEl.querySelector('#insert-row').addEventListener('click', async () => {
      const data = {};
      insertFormEl.querySelectorAll('[data-col]').forEach(inp => { data[inp.dataset.col] = inp.value; });
      try {
        await Api.post(`/v1/data/${projectId}/${tableName}`, data);
        renderRowsTab(projectId, tableName);
      } catch (e) { console.error('[SupaBein] Insert row failed', e); alert(e.message); }
    });

    const rowTable = rows.length
      ? el('table', { class: 'data-table' },
          el('thead', {}, el('tr', {}, ...allCols.map(c => el('th', {}, c.col_name)))),
          el('tbody', {}, ...rows.map(r =>
            el('tr', {}, ...allCols.map(c => el('td', {}, String(r[c.col_name] ?? ''))))
          ))
        )
      : el('div', { class: 'text-muted' }, 'No rows yet.');

    content.innerHTML = '';
    content.appendChild(insertFormEl);
    content.appendChild(el('div', { class: 'card mt-3' },
      el('div', { class: 'card-title' }, `Rows (showing up to 50 of ${res.count})`),
      rowTable
    ));
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
          <div class="form-group" style="width:180px"><label>API Role</label><input type="text" id="p-role" placeholder="anon / authenticated"></div>
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

async function loadDeployContent(projectId, siteId) {
  const content = document.getElementById('deploy-content');

  try {
    const [site, deploys] = await Promise.all([
      Api.get(`/v1/projects/${projectId}/sites/${siteId}`),
      Api.get(`/v1/projects/${projectId}/sites/${siteId}/deploys`)
    ]);

    const header = document.querySelector('.page-header');
    const existingViewBtn = header && header.querySelector('#view-site-btn');
    if (existingViewBtn) existingViewBtn.remove();
    if (header && site.current_deploy_id) {
      header.appendChild(
        el('a', { id: 'view-site-btn', class: 'btn btn-primary btn-sm', href: `/sites/s${siteId}/current/`, target: '_blank', rel: 'noopener' }, 'View Site →')
      );
    }

    const uploadForm = h(`
      <div class="card">
        <div class="card-title">Upload Deploy (zip)</div>
        <div class="form-group">
          <input type="file" id="zip-file" accept=".zip">
        </div>
        <button class="btn btn-primary" id="deploy-btn">Deploy</button>
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

      const fd = new FormData();
      fd.append('zipfile', file);

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

        status.textContent = 'Deployed!';
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
          el('tbody', {}, ...deploys.map(d => {
            const isCurrent = d.id === site.current_deploy_id;
            return el('tr', {},
              el('td', {}, d.version_label + (isCurrent ? ' ✓' : '')),
              el('td', {}, ...h(`<span>${statusBadge(d)}</span>`).children),
              el('td', { class: 'text-muted text-sm' }, d.size_bytes ? Math.round(d.size_bytes / 1024) + ' KB' : '—'),
              el('td', { class: 'text-muted text-sm' }, fmtDate(d.uploaded_at)),
              el('td', {},
                !isCurrent && d.status === 'ready'
                  ? el('button', {
                      class: 'btn btn-sm btn-secondary',
                      onClick: async () => {
                        if (!confirm('Roll back to this deploy?')) return;
                        try {
                          await Api.post(`/v1/projects/${projectId}/sites/${siteId}/deploys/${d.id}/rollback`);
                          loadDeployContent(projectId, siteId);
                        } catch (e) { alert(e.message); }
                      }
                    }, 'Rollback')
                  : ''
              )
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
  setApp('<div style="padding:48px;text-align:center"><h1 style="font-size:48px;color:var(--muted)">404</h1><p class="text-muted">Page not found</p><a href="#/projects">Go home</a></div>');
}

// ─── API Reference ────────────────────────────────────────────────────────────

async function renderApi({ id }) {
  if (!requireAuth()) return;
  renderLayout(id, 'api', [el('p', { class: 'text-muted' }, 'Loading API reference…')]);

  const baseUrl = window.location.origin + '/api/v1';
  const projectId = parseInt(id);

  let tables = [];
  try {
    tables = await Api.get(`/v1/projects/${id}/tables`);
  } catch (e) {
    renderLayout(id, 'api', [el('div', { class: 'alert alert-error' }, e.message)]);
    return;
  }

  function copyCode(btn, text) {
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(() => { btn.textContent = orig; }, 1500);
    });
  }

  function codeBlock(code) {
    const pre = el('pre', { class: 'api-code-block' }, code);
    const btn = el('button', { class: 'copy-btn btn btn-sm' }, 'Copy');
    btn.onclick = () => copyCode(btn, code);
    const wrap = el('div', { style: 'position:relative' }, pre, btn);
    return wrap;
  }

  function exampleTabs(curlCode, jsCode) {
    let active = 'curl';
    const curlBlock = codeBlock(curlCode);
    const jsBlock   = codeBlock(jsCode);
    jsBlock.style.display = 'none';

    const curlBtn = el('button', { class: 'api-tab-btn active' }, 'curl');
    const jsBtn   = el('button', { class: 'api-tab-btn' }, 'JavaScript');

    curlBtn.onclick = () => {
      if (active === 'curl') return;
      active = 'curl';
      curlBlock.style.display = ''; jsBlock.style.display = 'none';
      curlBtn.classList.add('active'); jsBtn.classList.remove('active');
    };
    jsBtn.onclick = () => {
      if (active === 'js') return;
      active = 'js';
      curlBlock.style.display = 'none'; jsBlock.style.display = '';
      jsBtn.classList.add('active'); curlBtn.classList.remove('active');
    };

    return el('div', {},
      el('div', { class: 'api-tab-row' }, curlBtn, jsBtn),
      curlBlock, jsBlock
    );
  }

  function methodBadge(method) {
    return el('span', { class: `method-badge method-${method.toLowerCase()}` }, method);
  }

  function endpointRow(method, path, desc, curlCode, jsCode) {
    const details = el('div', { class: 'api-endpoint-detail' }, exampleTabs(curlCode, jsCode));
    details.style.display = 'none';
    let open = false;
    const row = el('div', { class: 'endpoint-row' },
      methodBadge(method),
      el('span', { class: 'endpoint-path' }, path),
      el('span', { class: 'endpoint-desc' }, desc),
      el('button', { class: 'btn btn-sm', style: 'margin-left:auto;flex-shrink:0' }, '▾')
    );
    row.querySelector('button').onclick = () => {
      open = !open;
      details.style.display = open ? '' : 'none';
      row.querySelector('button').textContent = open ? '▴' : '▾';
    };
    return el('div', {}, row, details);
  }

  function tableCard(table) {
    const tname = table.table_name;
    const pname = table.physical_name;
    const listPath   = `/v1/data/${projectId}/${tname}`;
    const singlePath = `/v1/data/${projectId}/${tname}/:id`;

    const curlList = `curl -X GET "${baseUrl}/data/${projectId}/${tname}?limit=50" \\
  -H "Authorization: Bearer YOUR_TOKEN"`;
    const jsList = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}?limit=50', {
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});
const rows = await res.json();`;

    const curlCreate = `curl -X POST "${baseUrl}/data/${projectId}/${tname}" \\
  -H "Authorization: Bearer YOUR_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"column": "value"}'`;
    const jsCreate = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({ column: 'value' })
});
const row = await res.json();`;

    const curlGet = `curl -X GET "${baseUrl}/data/${projectId}/${tname}/1" \\
  -H "Authorization: Bearer YOUR_TOKEN"`;
    const jsGet = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}/1', {
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});
const row = await res.json();`;

    const curlPatch = `curl -X PATCH "${baseUrl}/data/${projectId}/${tname}/1" \\
  -H "Authorization: Bearer YOUR_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"column": "new_value"}'`;
    const jsPatch = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}/1', {
  method: 'PATCH',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({ column: 'new_value' })
});
const row = await res.json();`;

    const curlDelete = `curl -X DELETE "${baseUrl}/data/${projectId}/${tname}/1" \\
  -H "Authorization: Bearer YOUR_TOKEN"`;
    const jsDelete = `const res = await fetch('${baseUrl}/data/${projectId}/${tname}/1', {
  method: 'DELETE',
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});`;

    return el('div', { class: 'api-table-card' },
      el('div', { class: 'api-table-title' }, tname),
      el('div', { class: 'api-table-physical' }, pname),
      el('div', { style: 'margin-top:12px' },
        endpointRow('GET',    listPath,   'List rows',   curlList,   jsList),
        endpointRow('POST',   listPath,   'Insert row',  curlCreate, jsCreate),
        endpointRow('GET',    singlePath, 'Get row',     curlGet,    jsGet),
        endpointRow('PATCH',  singlePath, 'Update row',  curlPatch,  jsPatch),
        endpointRow('DELETE', singlePath, 'Delete row',  curlDelete, jsDelete),
      )
    );
  }

  // Auth section
  const loginCurl = `curl -X POST "${baseUrl}/auth/login" \\
  -H "Content-Type: application/json" \\
  -d '{"email": "you@example.com", "password": "yourpassword"}'`;
  const loginJs = `const res = await fetch('${baseUrl}/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: 'you@example.com', password: 'yourpassword' })
});
const { token } = await res.json();
// Use token in subsequent requests:
// Authorization: Bearer <token>`;

  const authCard = el('div', { class: 'api-table-card' },
    el('div', { class: 'api-table-title' }, 'Authentication'),
    el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin:6px 0 12px' },
      'Include an ', el('code', { style: 'background:var(--border);padding:2px 5px;border-radius:3px' }, 'Authorization: Bearer <token>'),
      ' header on requests to tables with restricted policies.'
    ),
    endpointRow('POST', '/v1/auth/login', 'Get a JWT token', loginCurl, loginJs)
  );

  const emptyState = tables.length === 0
    ? el('div', { class: 'api-table-card', style: 'text-align:center;padding:40px' },
        el('p', { class: 'text-muted' }, 'No tables yet — create one to see your auto-generated API endpoints.'),
        el('a', { href: `#/projects/${id}/tables`, class: 'btn btn-primary', style: 'display:inline-block;margin-top:12px' }, 'Create a Table')
      )
    : null;

  const content = [
    el('div', { class: 'page-header' },
      el('div', {},
        el('h1', { class: 'page-title' }, 'API Reference'),
        el('p', { class: 'text-muted', style: 'font-size:0.82rem;margin-top:2px' }, 'Auto-generated REST API for your project')
      ),
      el('span', { class: 'api-base-url' }, baseUrl)
    ),
    authCard,
    ...(emptyState ? [emptyState] : tables.map(tableCard))
  ];

  renderLayout(id, 'api', content);
}

// ─── Routes ──────────────────────────────────────────────────────────────────

Router.add('', () => {
  if (Auth.isLoggedIn()) Router.navigate('/projects');
  else Router.navigate('/login');
});

Router.add('login',  renderLogin);
Router.add('signup', renderSignup);

Router.add('logout', () => {
  Auth.clear();
  Router.navigate('/login');
});

Router.add('projects',                              renderProjects);
Router.add('projects/:id',                          renderProject);
Router.add('projects/:id/tables',                   renderTables);
Router.add('projects/:id/tables/:name',             renderTableEditor);
Router.add('projects/:id/sites',                    renderSites);
Router.add('projects/:id/sites/:site_id',           renderSiteManager);
Router.add('projects/:id/api',                      renderApi);

// ─── Boot ─────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => Router.init());

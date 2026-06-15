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
      el('a', { href: `#/projects/${projectId}/sites`, class: activeTab === 'sites' ? 'active' : '' }, 'Sites'),
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
        el('a', { class: 'btn btn-secondary', href: `#/projects/${id}/sites` }, 'Sites')
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

  renderLayout(id, 'sites', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, 'Sites'),
      el('button', { class: 'btn btn-primary', id: 'new-site' }, '+ New Site')
    ),
    el('div', { id: 'site-list' }, 'Loading...')
  ]);

  document.getElementById('new-site').addEventListener('click', () => showNewSiteModal(id));

  try {
    const sites = await Api.get(`/v1/projects/${id}/sites`);

    // One site per project — go straight to the manager
    if (sites.length === 1) {
      Router.navigate(`/projects/${id}/sites/${sites[0].id}`);
      return;
    }

    const list = document.getElementById('site-list');

    if (!sites.length) {
      list.innerHTML = '<div class="text-muted">No sites yet. Click "+ New Site" to create one.</div>';
      return;
    }

    const tbl = el('table', { class: 'data-table' },
      el('thead', {}, el('tr', {},
        el('th', {}, 'Subdomain'), el('th', {}, 'SPA Mode'), el('th', {}, 'Current Deploy'), el('th', {}, '')
      )),
      el('tbody', {}, ...sites.map(s =>
        el('tr', {},
          el('td', {}, el('a', { href: `#/projects/${id}/sites/${s.id}` }, s.subdomain)),
          el('td', {}, s.spa_mode ? 'Yes' : 'No'),
          el('td', { class: 'text-muted text-sm' }, s.current_version ? `${s.current_version} (${fmtDate(s.deployed_at)})` : 'None'),
          el('td', { style: 'display:flex;gap:6px;flex-wrap:wrap' },
            el('a', { class: 'btn btn-sm btn-secondary', href: `#/projects/${id}/sites/${s.id}` }, 'Manage'),
            s.current_deploy_id
              ? el('a', { class: 'btn btn-sm btn-primary', href: `/sites/s${s.id}/current/`, target: '_blank', rel: 'noopener' }, 'View →')
              : ''
          )
        )
      ))
    );

    list.innerHTML = '';
    list.appendChild(tbl);
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

  renderLayout(id, 'sites', [
    el('div', { class: 'page-header' },
      el('h1', { class: 'page-title' }, 'Deploy'),
      el('a', { class: 'btn btn-secondary btn-sm', href: `#/projects/${id}/sites` }, '← Sites')
    ),
    el('div', { id: 'deploy-content' }, 'Loading...')
  ]);

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

    content.innerHTML = '';
    content.appendChild(uploadFormEl);
    content.appendChild(el('div', { class: 'card mt-3' },
      el('div', { class: 'card-title' }, 'Deploy History'),
      deployTable
    ));
  } catch (e) {
    content.innerHTML = `<div class="alert alert-error">${e.message}</div>`;
  }
}

function render404() {
  setApp('<div style="padding:48px;text-align:center"><h1 style="font-size:48px;color:var(--muted)">404</h1><p class="text-muted">Page not found</p><a href="#/projects">Go home</a></div>');
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

// ─── Boot ─────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => Router.init());

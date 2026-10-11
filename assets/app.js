'use strict';
/* UserBot Control Center — panel front-end (vanilla, XSS-safe via DOM building) */

const BOOT = window.__BOOT || {};
const $ = (id) => document.getElementById(id);
const GET_ACTIONS = new Set(['accounts', 'account', 'qr_status', 'log', 'campaigns', 'chats', 'history', 'archive']);

/* ---------- safe DOM builder ---------- */
function el(tag, props, ...children) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(props || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k === 'svg') e.innerHTML = v;               // trusted server-rendered QR/SVG only
    else if (k === 'dataset') Object.assign(e.dataset, v);
    else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v);
    else e.setAttribute(k, v);
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined || c === false) continue;
    e.append(c.nodeType ? c : document.createTextNode(String(c)));
  }
  return e;
}

/* ---------- icons (line-style SVG, enterprise look) ---------- */
const SVG = {
  play: '<path d="M7 5l12 7-12 7z"/>',
  stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
  restart: '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 4v5h-5"/>',
  qr: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M17 21h.01M21 17v4h-4"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
  trash: '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
  back: '<path d="M5 12h14M12 5l7 7-7 7"/>',
  save: '<path d="M5 4h11l3 3v13H5z"/><path d="M8 4v5h7M8 20v-6h8v6"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 4v5h-5"/>',
  send: '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
  image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
  video: '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3z"/>',
  mic: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>',
  music: '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
  film: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/>',
  file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
  pin: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  smile: '<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
  chart: '<path d="M4 20V10M10 20V4M16 20v-7"/><path d="M3 20h18"/>',
  inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5 6h14l3 6v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>',
  message: '<path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  edit: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  terminal: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3M13 15h4"/>',
  users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><path d="M16 5.5a3.5 3.5 0 0 1 0 7M22 20c0-2.6-1.6-4.3-3.8-5"/>',
  power: '<path d="M12 3v8"/><path d="M6.4 6.4a8 8 0 1 0 11.2 0"/>',
  key: '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l2 2M15 8l1.5 1.5"/>',
  megaphone: '<path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15 8a4 4 0 0 1 0 8"/>',
  layers: '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
  poll: '<path d="M4 20V10M10 20V4M16 20v-7"/><path d="M3 20h18"/>',
};
function icon(name, cls = 'ico-svg') {
  const span = document.createElement('span');
  span.style.display = 'inline-flex';
  span.innerHTML = `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${SVG[name] || ''}</svg>`;
  return span.firstElementChild;
}

/* ---------- api ---------- */
async function api(action, data = {}, method) {
  method = method || (GET_ACTIONS.has(action) ? 'GET' : 'POST');
  let url = 'api.php?action=' + encodeURIComponent(action);
  const opt = { method };
  if (method === 'POST') {
    const f = new FormData();
    f.append('csrf', BOOT.csrf);
    for (const [k, v] of Object.entries(data)) f.append(k, v);
    opt.body = f;
  } else {
    const q = new URLSearchParams(data).toString();
    if (q) url += '&' + q;
  }
  let r;
  try { r = await fetch(url, opt); } catch (e) { return { ok: false, error: 'اتصال به سرور برقرار نشد.' }; }
  if (r.status === 401) { location.reload(); return { ok: false, error: 'unauthorized' }; }
  try { return await r.json(); } catch (e) { return { ok: false, error: 'پاسخ نامعتبر سرور (' + r.status + ').' }; }
}

/* ---------- toast ---------- */
let toastTimer = null;
function toast(msg, kind = '') {
  const t = $('toast');
  t.innerHTML = '';
  if (kind === 'ok') t.append(icon('check'));
  else if (kind === 'bad') t.append(icon('x'));
  t.append(document.createTextNode(msg || ''));
  t.className = 'toast show ' + kind;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.className = 'toast'), 3600);
}
function flash(res) {
  if (!res) return;
  toast(res.message || res.error || '', res.ok ? 'ok' : 'bad');
}

/* ---------- modal ---------- */
function openModal(node, small = false) {
  const box = el('div', { class: 'modalbox glass' + (small ? ' sm' : '') }, node);
  const overlay = el('div', { class: 'modal', onclick: (e) => { if (e.target === overlay) closeModal(); } }, box);
  $('modalRoot').innerHTML = '';
  $('modalRoot').append(overlay);
  return box;
}
function closeModal() { $('modalRoot').innerHTML = ''; if (window.__qrTimer) { clearTimeout(window.__qrTimer); window.__qrTimer = null; } }

function confirmDialog(title, body, onYes, yesLabel = 'تأیید', danger = true) {
  const box = openModal(el('div', {},
    el('h2', {}, title),
    el('p', { class: 'muted' }, body),
    el('div', { class: 'row spread', style: 'margin-top:18px' },
      el('button', { class: 'btn ghost', onclick: closeModal }, 'انصراف'),
      el('button', { class: 'btn ' + (danger ? 'danger' : 'primary'), onclick: async () => { closeModal(); await onYes(); } }, yesLabel)
    )
  ), true);
  return box;
}

/* ---------- helpers ---------- */
function fmtUptime(sec) {
  if (!sec || sec < 0) return '—';
  const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
  if (d) return `${d}روز ${h}س`;
  if (h) return `${h}س ${m}د`;
  return `${m}دقیقه`;
}
function initial(s) { return (s || '?').trim().slice(0, 1).toUpperCase(); }
function switchEl(checked, onChange) {
  const input = el('input', { type: 'checkbox', onchange: (e) => onChange(e.target.checked) });
  if (checked) input.checked = true;
  return el('label', { class: 'switch' }, input, el('span', { class: 'slider' }));
}

/* ---------- routing ---------- */
const route = { view: 'dashboard', key: null, tab: 'overview' };
let accounts = [];
let refreshTimer = null;

function setActiveNav() {
  document.querySelectorAll('#nav button').forEach((b) =>
    b.classList.toggle('active', b.dataset.view === route.view));
}

function go(view, key = null) {
  route.view = view; route.key = key;
  if (view === 'account') route.tab = 'overview';
  setActiveNav();
  render();
}

async function render() {
  const v = $('view');
  if (route.view === 'dashboard') return renderDashboard(v);
  if (route.view === 'add') return renderAdd(v);
  if (route.view === 'about') return renderAbout(v);
  if (route.view === 'account') return renderAccount(v);
}

/* ---------- dashboard ---------- */
async function loadAccounts() {
  const r = await api('accounts');
  if (r.ok) accounts = r.accounts || [];
  return r;
}

async function renderDashboard(v) {
  const r = await loadAccounts();
  if (!r.ok) { v.innerHTML = ''; v.append(el('div', { class: 'card empty' }, r.error || 'خطا')); return; }

  const running = accounts.filter((a) => a.status.running).length;
  const logged = accounts.filter((a) => a.status.logged_in).length;
  const camps = accounts.reduce((n, a) => n + (a.status.campaign_count || 0), 0);

  const stats = el('div', { class: 'stats' },
    statCard('users', accounts.length, 'کل اکانت‌ها'),
    statCard('power', running, 'روشن'),
    statCard('key', logged, 'لاگین‌شده'),
    statCard('megaphone', camps, 'کمپین فعال')
  );

  const grid = el('div', { class: 'grid' });
  if (!accounts.length) {
    grid.append(el('div', { class: 'card empty' }, 'هنوز اکانتی نساختی. از «افزودن اکانت» شروع کن.'));
  }
  accounts.forEach((a) => grid.append(accountCard(a)));

  v.innerHTML = '';
  v.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('h1', {}, 'داشبورد'), el('div', { class: 'sub' }, 'مدیریت، لاگین و کنترل کامل اکانت‌ها')),
      el('div', { class: 'row' },
        (logged > running) ? el('button', {
          class: 'btn green', title: 'روشن کردن همه‌ی اکانت‌های لاگین‌شده',
          onclick: () => confirmDialog('روشن کردن همه', 'همه‌ی اکانت‌های لاگین‌شده که خاموش‌اند روشن می‌شوند.', async () => {
            toast('در حال روشن کردن همه...', '');
            flash(await api('start_all'));
            renderDashboard($('view'));
          }, 'روشن کن همه', false)
        }, icon('power'), 'روشن کردن همه') : null,
        running ? el('button', {
          class: 'btn orange', title: 'خاموش کردن همه‌ی اکانت‌های روشن',
          onclick: () => confirmDialog('خاموش کردن همه', running + ' اکانت روشن خاموش می‌شوند.', async () => {
            toast('در حال خاموش کردن همه...', '');
            flash(await api('stop_all'));
            renderDashboard($('view'));
          }, 'خاموش کن همه')
        }, icon('stop'), 'خاموش کردن همه') : null,
        running ? el('button', {
          class: 'btn ghost', title: 'بعد از آپدیت، کد جدید را روی همه‌ی اکانت‌های روشن اعمال می‌کند',
          onclick: () => confirmDialog('اعمال آپدیت روی همه', running + ' اکانت روشن دوباره راه‌اندازی می‌شوند تا کد جدید بارگذاری شود. اطلاعات و session‌ها پاک نمی‌شوند.', async () => {
            toast('در حال ری‌استارت همه...', '');
            flash(await api('restart_all'));
            renderDashboard($('view'));
          }, 'ری‌استارت همه', false)
        }, icon('restart'), 'اعمال آپدیت روی همه') : null,
        el('button', { class: 'btn primary', onclick: () => go('add') }, icon('plus'), 'افزودن اکانت')
      )
    ),
    stats, grid
  );
}

function statCard(ico, n, label) {
  return el('div', { class: 'card stat' },
    el('div', { class: 'ico' }, icon(ico)),
    el('div', {},
      el('div', { class: 'n' }, String(n)),
      el('div', { class: 'l' }, label)));
}

function accountCard(a) {
  const s = a.status;
  return el('div', { class: 'card account' },
    el('div', { class: 'top-row' },
      el('div', { class: 'avatar' }, initial(a.name)),
      el('div', { style: 'min-width:0' },
        el('div', { class: 'name' }, a.name),
        el('div', { class: 'key' }, a.key)
      ),
      el('span', { class: 'badge ' + (s.running ? 'on' : 'off'), style: 'margin-right:auto' },
        el('span', { class: 'dot' + (s.running ? ' on' : '') }), s.running ? 'روشن' : 'خاموش')
    ),
    el('div', { class: 'meta' },
      el('span', {}, icon('key'), s.logged_in ? 'لاگین‌شده' : 'نیاز به لاگین'),
      s.running ? el('span', {}, icon('clock'), fmtUptime(s.uptime)) : null,
      el('span', {}, icon('layers'), (s.cache_count || 0) + ' cache'),
      el('span', {}, icon('megaphone'), (s.campaign_count || 0) + ' کمپین')
    ),
    el('div', { class: 'row' },
      !s.logged_in
        ? el('button', { class: 'btn primary small', onclick: () => openQr(a.key) }, icon('qr'), 'QR Login')
        : (s.running
          ? el('button', { class: 'btn orange small', onclick: () => act('stop', a.key) }, icon('stop'), 'توقف')
          : el('button', { class: 'btn green small', onclick: () => act('start', a.key) }, icon('play'), 'شروع')),
      s.running ? el('button', { class: 'btn ghost icon-btn small', title: 'Restart', onclick: () => act('restart', a.key) }, icon('restart')) : null,
      el('button', { class: 'btn small', onclick: () => go('account', a.key) }, icon('settings'), 'مدیریت'),
      el('button', { class: 'btn danger icon-btn small', title: 'حذف', onclick: () => removeAccount(a) }, icon('trash'))
    )
  );
}

async function act(action, key) {
  toast('در حال انجام...', '');
  const r = await api(action, { key });
  flash(r);
  await renderDashboard($('view'));
}

function removeAccount(a) {
  const purgeInput = el('input', { type: 'checkbox' });
  openModal(el('div', {},
    el('h2', {}, 'حذف اکانت ' + a.name),
    el('p', { class: 'muted' }, 'اکانت از لیست حذف می‌شود.'),
    el('label', { class: 'row', style: 'gap:8px;margin:10px 0' }, purgeInput,
      el('span', {}, 'پاک‌سازی کامل پوشه (session، cache، مدیا) — غیرقابل بازگشت')),
    el('div', { class: 'row spread', style: 'margin-top:14px' },
      el('button', { class: 'btn ghost', onclick: closeModal }, 'انصراف'),
      el('button', {
        class: 'btn danger', onclick: async () => {
          closeModal();
          const r = await api('remove', { key: a.key, purge: purgeInput.checked ? '1' : '0' });
          flash(r); renderDashboard($('view'));
        }
      }, 'حذف')
    )
  ), true);
}

/* ---------- add account ---------- */
function renderAdd(v) {
  const name = el('input', { placeholder: 'نام اکانت، مثلاً RoboVN' });
  const key = el('input', { placeholder: 'کلید انگلیسی، مثلاً robovn (اختیاری)' });
  v.innerHTML = '';
  v.append(
    el('div', { class: 'page-head' }, el('h1', {}, 'افزودن اکانت')),
    el('div', { class: 'card', style: 'max-width:560px' },
      el('div', { class: 'stack' },
        el('label', { class: 'field' }, 'نام نمایشی', name),
        el('label', { class: 'field' }, 'کلید یکتا (انگلیسی، بدون فاصله)', key),
        el('button', {
          class: 'btn primary', onclick: async () => {
            if (!name.value.trim()) return toast('نام را وارد کن', 'bad');
            const r = await api('create_account', { name: name.value.trim(), key: key.value.trim() });
            if (!r.ok) return flash(r);
            toast('اکانت ساخته شد', 'ok');
            await loadAccounts();
            go('dashboard');
            openQr(r.account.key);
          }
        }, '+ ساخت اکانت و نمایش QR')
      )
    )
  );
  name.focus();
}

/* ---------- QR login ---------- */
async function openQr(key) {
  const svgBox = el('div', { class: 'qrsvg' }, el('span', { class: 'loading' }, el('span', { class: 'spin' }), 'در حال شروع...'));
  const statusBox = el('div', { class: 'card' }, 'در حال آماده‌سازی...');
  const twofaPass = el('input', { type: 'password', placeholder: 'رمز دو مرحله‌ای (2FA)' });
  const twofaBox = el('div', { class: 'card', style: 'display:none;margin-top:12px' },
    el('h3', {}, 'رمز دو مرحله‌ای'),
    twofaPass,
    el('button', {
      class: 'btn primary', style: 'margin-top:10px', onclick: async () => {
        if (!twofaPass.value) return toast('رمز را وارد کن', 'bad');
        const r = await api('complete_2fa', { key, password: twofaPass.value });
        flash(r);
      }
    }, 'تکمیل ورود')
  );

  openModal(el('div', {},
    el('div', { class: 'row spread' }, el('h2', {}, 'ورود با QR'), el('button', { class: 'btn ghost small', onclick: closeModal }, '✕')),
    el('div', { class: 'qrbox' },
      svgBox,
      el('div', {},
        el('p', { class: 'muted' }, 'تلگرام موبایل → Settings → Devices → Link Desktop Device و این QR را اسکن کن.'),
        statusBox, twofaBox
      )
    )
  ));

  const start = await api('qr_start', { key });
  if (!start.ok) { svgBox.textContent = 'خطا'; statusBox.textContent = start.error || 'خطا در شروع QR'; return; }

  const poll = async () => {
    const r = await api('qr_status', { key });
    statusBox.textContent = (r.message || r.status || '...') + (r.updated_at ? ' — ' + r.updated_at : '');
    if (r.svg) { svgBox.innerHTML = ''; svgBox.append(el('div', { svg: r.svg })); }
    if (r.status === 'needs_2fa' || r.needs_2fa) { twofaBox.style.display = 'block'; }
    if (r.status === 'logged_in' || r.logged_in) {
      svgBox.innerHTML = '<b style="color:#16a34a;font-size:18px">✅ ورود موفق</b>';
      statusBox.textContent = 'ورود کامل شد. اکانت آماده‌ی Start است.';
      await loadAccounts();
      if (route.view === 'dashboard') renderDashboard($('view'));
      window.__qrTimer = setTimeout(closeModal, 1500);
      return;
    }
    if (r.status === 'error') { return; }
    window.__qrTimer = setTimeout(poll, 1500);
  };
  window.__qrTimer = setTimeout(poll, 1000);
}

/* ---------- account detail ---------- */
let detail = null;
const TABS = [
  ['overview', 'نمای کلی', 'chart'],
  ['archive', 'آرشیو حذف‌شده‌ها', 'inbox'],
  ['chats', 'چت‌ها', 'message'],
  ['deleted', 'تنظیم حذف', 'trash'],
  ['edited', 'تنظیم ویرایش', 'edit'],
  ['clock', 'ساعت پروفایل', 'clock'],
  ['auto', 'ارسال خودکار', 'megaphone'],
  ['logs', 'لاگ‌ها', 'terminal'],
];

async function renderAccount(v) {
  v.innerHTML = '';
  v.append(el('div', { class: 'loading' }, el('span', { class: 'spin' }), 'در حال دریافت...'));
  const r = await api('account', { key: route.key });
  if (!r.ok) { v.innerHTML = ''; v.append(el('div', { class: 'card empty' }, r.error || 'خطا')); return; }
  detail = r;
  const a = r.account, s = a.status;

  const head = el('div', { class: 'page-head' },
    el('div', { class: 'row', style: 'gap:14px' },
      el('button', { class: 'btn ghost icon-btn small', title: 'بازگشت', onclick: () => go('dashboard') }, icon('back')),
      el('div', { class: 'avatar' }, initial(a.name)),
      el('div', {},
        el('h1', { style: 'font-size:20px' }, a.name),
        el('div', { class: 'muted', style: 'font-size:12px' }, a.key + ' · ' + (s.running ? 'روشن' : 'خاموش'))
      )
    ),
    el('div', { class: 'row' },
      !s.logged_in ? el('button', { class: 'btn primary small', onclick: () => openQr(a.key) }, icon('qr'), 'QR Login') : null,
      s.logged_in && !s.running ? el('button', { class: 'btn green small', onclick: () => detailAct('start') }, icon('play'), 'شروع') : null,
      s.running ? el('button', { class: 'btn orange small', onclick: () => detailAct('stop') }, icon('stop'), 'توقف') : null,
      s.running ? el('button', { class: 'btn ghost small', onclick: () => detailAct('restart') }, icon('restart'), 'ری‌استارت') : null
    )
  );

  const tabBar = el('div', { class: 'tabs' });
  TABS.forEach(([id, label, ic]) => tabBar.append(
    el('button', { class: 'tab' + (route.tab === id ? ' active' : ''), onclick: () => { route.tab = id; renderAccount(v); } }, icon(ic), label)
  ));

  const body = el('div', {});
  v.innerHTML = '';
  v.append(head, tabBar, body);

  if (route.tab === 'overview') tabOverview(body, r);
  else if (route.tab === 'archive') tabArchive(body, r);
  else if (route.tab === 'deleted') tabDeleted(body, r);
  else if (route.tab === 'edited') tabEdited(body, r);
  else if (route.tab === 'clock') tabClock(body, r);
  else if (route.tab === 'auto') tabAuto(body, r);
  else if (route.tab === 'chats') tabChats(body, r);
  else if (route.tab === 'logs') tabLogs(body, r);
}

async function detailAct(action) {
  toast('در حال انجام...', '');
  const r = await api(action, { key: route.key });
  flash(r);
  renderAccount($('view'));
}

function tabOverview(body, r) {
  const a = r.account, s = a.status;
  const info = (label, val) => el('div', { class: 'toggle-line' }, el('span', { class: 'muted' }, label), el('b', {}, val));
  body.append(
    el('div', { class: 'grid' },
      el('div', { class: 'card' },
        el('h3', {}, 'وضعیت'),
        info('اجرا', s.running ? 'روشن (PID ' + s.pid + ')' : 'خاموش'),
        info('لاگین', s.logged_in ? 'بله' : 'خیر'),
        info('آپ‌تایم', fmtUptime(s.uptime)),
        info('پیام‌های cache شده', String(s.cache_count || 0)),
        info('کمپین‌ها', String(s.campaign_count || 0))
      ),
      el('div', { class: 'card' },
        el('h3', {}, 'خلاصه قابلیت‌ها'),
        info('بازیابی حذف', r.delete.enabled ? 'روشن' : 'خاموش'),
        info('بازیابی ویرایش', r.edit.enabled ? 'روشن' : 'خاموش'),
        info('ساعت پروفایل', r.clock.enabled ? 'روشن' : 'خاموش'),
        info('ارسال خودکار فعال', String(r.campaigns.filter((c) => c.enabled).length))
      )
    )
  );
}

/* deleted / edited share most fields */
function recoveryForm(body, kind, state, saveAction) {
  const enabled = state.enabled;
  const targetSel = el('select', {},
    el('option', { value: 'save_message' }, 'Saved Messages (پیام‌های ذخیره)'),
    el('option', { value: 'channel' }, 'کانال/گروه دیگر'));
  targetSel.value = state.target_type || 'save_message';
  const channel = el('input', { placeholder: 'username یا آیدی کانال', value: state.channel_peer || '' });
  const channelWrap = el('label', { class: 'field' }, 'مقصد کانال', channel);
  const filter = el('select', {},
    el('option', { value: 'all' }, 'همه چت‌ها'),
    el('option', { value: 'private' }, 'فقط شخصی'),
    el('option', { value: 'groups' }, 'فقط گروه‌ها'),
    el('option', { value: 'channels' }, 'فقط کانال‌ها'));
  filter.value = state.chat_filter || 'all';

  const syncChannel = () => { channelWrap.style.display = targetSel.value === 'channel' ? 'flex' : 'none'; };
  targetSel.addEventListener('change', syncChannel); syncChannel();

  let enableState = enabled;
  const extra = [];
  const thresh = el('input', { type: 'number', min: '5', max: '500', value: state.full_chat_threshold || 25 });
  let fullChatState = state.full_chat_enabled;
  if (kind === 'delete') {
    extra.push(
      el('div', { class: 'toggle-line' },
        el('span', {}, 'بازیابی کل تاریخچه هنگام حذف انبوه'),
        switchEl(state.full_chat_enabled, (c) => fullChatState = c)),
      el('label', { class: 'field' }, 'آستانه تشخیص حذف کل چت (تعداد پیام)', thresh)
    );
  }

  const card = el('div', { class: 'card', style: 'max-width:620px' },
    el('div', { class: 'toggle-line' },
      el('b', {}, kind === 'delete' ? 'ذخیره پیام‌های حذف‌شده' : 'ذخیره پیام‌های ویرایش‌شده'),
      switchEl(enabled, (c) => enableState = c)),
    el('label', { class: 'field', style: 'margin-top:12px' }, 'مقصد ذخیره', targetSel),
    channelWrap,
    el('label', { class: 'field' }, 'فیلتر نوع چت', filter),
    ...extra,
    el('button', {
      class: 'btn primary', style: 'margin-top:16px', onclick: async () => {
        const data = {
          key: route.key,
          enabled: enableState ? '1' : '0',
          target_type: targetSel.value,
          channel_peer: channel.value.trim(),
          chat_filter: filter.value,
        };
        if (kind === 'delete') {
          data.full_chat_enabled = fullChatState ? '1' : '0';
          data.full_chat_threshold = thresh.value;
        }
        const res = await api(saveAction, data);
        flash(res);
      }
    }, icon('save'), 'ذخیره تنظیمات')
  );
  body.append(card);
}

function tabDeleted(body, r) { recoveryForm(body, 'delete', r.delete, 'set_delete'); }
function tabEdited(body, r) { recoveryForm(body, 'edit', r.edit, 'set_edit'); }

function tabClock(body, r) {
  let on = r.clock.enabled;
  body.append(el('div', { class: 'card', style: 'max-width:560px' },
    el('p', { class: 'muted' }, 'ساعت تهران را کنار نام پروفایل نمایش می‌دهد و هر دقیقه به‌روزرسانی می‌کند. با خاموش کردن، نام به حالت اولیه برمی‌گردد.'),
    el('div', { class: 'toggle-line' }, el('b', {}, 'نمایش ساعت روی پروفایل'), switchEl(on, async (c) => {
      const res = await api('set_clock', { key: route.key, enabled: c ? '1' : '0' });
      flash(res);
    })),
    !r.account.status.running ? el('p', { class: 'badge warn', style: 'margin-top:12px;display:inline-block' }, 'برای اعمال شدن باید اکانت روشن باشد') : null
  ));
}

/* ---------- auto campaigns ---------- */
function tabAuto(body, r) {
  const targets = el('textarea', { placeholder: 'username مقصدها، با ویرگول یا خط جدید\nمثال: channel1, group2' });
  const message = el('textarea', { placeholder: 'متن پیامی که ارسال می‌شود' });
  const interval = el('input', { type: 'number', min: '10', value: '30' });

  const list = el('div', { class: 'stack' });
  const renderList = (camps) => {
    list.innerHTML = '';
    if (!camps.length) { list.append(el('div', { class: 'empty' }, 'هیچ کمپینی ثبت نشده.')); return; }
    camps.forEach((c) => {
      const next = c.next_run_at ? new Date(c.next_run_at * 1000).toLocaleString('fa-IR') : '—';
      list.append(el('div', { class: 'card' },
        el('div', { class: 'row spread' },
          el('b', {}, c.id),
          el('span', { class: 'badge ' + (c.enabled ? 'on' : 'off') }, c.enabled ? 'فعال' : 'متوقف')),
        el('div', { class: 'meta' },
          el('span', {}, 'هر ' + c.interval_minutes + ' دقیقه'),
          el('span', {}, '🎯 ' + (c.targets || []).join(', ')),
          el('span', {}, 'بعدی: ' + next)),
        el('div', { style: 'white-space:pre-wrap;font-size:13px;margin:6px 0;color:var(--muted)' },
          (c.message || '').slice(0, 200)),
        el('div', { class: 'row' },
          el('button', {
            class: 'btn small ' + (c.enabled ? 'orange' : 'green'),
            onclick: async () => { flash(await api('campaign_toggle', { key: route.key, id: c.id, enabled: c.enabled ? '0' : '1' })); reload(); }
          }, c.enabled ? 'توقف' : 'فعال‌سازی'),
          el('button', {
            class: 'btn danger small',
            onclick: () => confirmDialog('حذف کمپین', c.id + ' حذف شود؟', async () => { flash(await api('campaign_delete', { key: route.key, id: c.id })); reload(); }, 'حذف')
          }, 'حذف')
        )
      ));
    });
  };
  const reload = async () => { const res = await api('campaigns', { key: route.key }); if (res.ok) renderList(res.campaigns); };

  body.append(
    el('div', { class: 'card', style: 'max-width:620px;margin-bottom:18px' },
      el('h3', {}, 'کمپین جدید'),
      el('div', { class: 'stack' },
        el('label', { class: 'field' }, 'مقصدها (فقط username عمومی)', targets),
        el('label', { class: 'field' }, 'متن پیام', message),
        el('label', { class: 'field' }, 'فاصله ارسال (دقیقه، حداقل ۱۰)', interval),
        el('button', {
          class: 'btn primary', onclick: async () => {
            const res = await api('campaign_create', { key: route.key, targets: targets.value, message: message.value, interval: interval.value });
            flash(res);
            if (res.ok) { targets.value = message.value = ''; renderList(res.campaigns); }
          }
        }, '+ ساخت کمپین')
      )
    ),
    el('h3', { style: 'margin:0 0 10px' }, 'کمپین‌های ثبت‌شده'),
    list
  );
  renderList(r.campaigns || []);
}

/* ---------- chats (full two-column, Telegram-like) ---------- */
const chatState = { items: [], filter: 'all', active: null };

function kindLabel(k) { return k === 'private' ? 'شخصی' : k === 'group' ? 'گروه' : k === 'channel' ? 'کانال' : ''; }
function mediaLabel(t) { return { photo: 'عکس', video: 'ویدیو', voice: 'پیام صوتی', audio: 'صوت', gif: 'گیف', sticker: 'استیکر', document: 'فایل', location: 'موقعیت', contact: 'مخاطب', poll: 'نظرسنجی' }[t] || t; }

function lightbox(src) {
  const lb = el('div', { class: 'lightbox', onclick: () => lb.remove() }, el('img', { src }));
  document.body.append(lb);
}

async function tabChats(body, r) {
  if (!r.account.status.running) {
    body.append(el('div', { class: 'card empty' }, 'برای مشاهده چت‌ها ابتدا اکانت را Start کن.'));
    return;
  }
  chatState.active = null;

  const search = el('input', { placeholder: 'جستجوی گفتگو...' });
  const filters = el('div', { class: 'chat-tabs' });
  const listScroll = el('div', { class: 'chat-scroll' });
  const status = el('div', { class: 'chat-status muted' });
  const refreshBtn = el('button', { class: 'btn ghost icon-btn small', title: 'به‌روزرسانی لیست گفتگوها' }, icon('refresh'));
  const topbar = el('div', { class: 'chat-topbar' }, status, refreshBtn);
  const aside = el('div', { class: 'chat-aside' },
    el('div', { class: 'chat-search' }, search, icon('search')), topbar, filters, listScroll);
  const main = el('div', { class: 'chat-main' }, el('div', { class: 'chat-empty' }, icon('message', 'ico-svg'), 'یک گفتگو را از لیست انتخاب کن.'));
  body.append(el('div', { class: 'chat-layout' }, aside, main));

  const counts = { all: 0, private: 0, group: 0, channel: 0 };

  const renderFilters = () => {
    filters.innerHTML = '';
    [['all', 'همه'], ['private', 'شخصی'], ['group', 'گروه'], ['channel', 'کانال']].forEach(([k, label]) =>
      filters.append(el('button', { class: chatState.filter === k ? 'active' : '', onclick: () => { chatState.filter = k; renderFilters(); renderList(); } }, label + ' ' + (counts[k] || 0))));
  };
  const renderList = () => {
    const q = search.value.trim().toLowerCase();
    listScroll.innerHTML = '';
    const filtered = chatState.items.filter((c) =>
      (chatState.filter === 'all' || c.kind === chatState.filter) &&
      (!q || c.title.toLowerCase().includes(q) || (c.username || '').toLowerCase().includes(q)));
    if (!filtered.length) { listScroll.append(el('div', { class: 'empty' }, 'موردی نیست')); return; }
    filtered.forEach((c) => listScroll.append(
      el('div', {
        class: 'chatitem' + (chatState.active === c.peer ? ' active' : ''),
        onclick: () => { chatState.active = c.peer; renderList(); openHistoryInto(main, c); }
      },
        el('div', { class: 'avatar' }, initial(c.title)),
        el('div', { class: 'grow' },
          el('div', { class: 'title' }, c.title),
          el('div', { class: 'sub' }, kindLabel(c.kind) + (c.username ? ' · ' + c.username : ''))))
    ));
  };

  const load = async (refresh) => {
    refreshBtn.disabled = true;
    listScroll.innerHTML = '';
    listScroll.append(el('div', { class: 'loading' }, el('span', { class: 'spin' }), 'دریافت گفتگوها...'));
    status.textContent = refresh ? 'در حال به‌روزرسانی...' : 'در حال بارگذاری...';
    const res = await api('chats', refresh ? { key: route.key, refresh: '1' } : { key: route.key });
    refreshBtn.disabled = false;
    if (!res.ok) { listScroll.innerHTML = ''; listScroll.append(el('div', { class: 'empty' }, res.error || 'خطا')); status.textContent = ''; return; }
    chatState.items = res.items || [];
    counts.all = chatState.items.length; counts.private = counts.group = counts.channel = 0;
    chatState.items.forEach((c) => (counts[c.kind] = (counts[c.kind] || 0) + 1));

    const when = res.cached_at ? new Date(res.cached_at * 1000).toLocaleTimeString('fa-IR') : '';
    let s = chatState.items.length + ' گفتگو';
    if (res.from_cache) s += ' · از کش ' + when;
    else if (when) s += ' · ' + when;
    if (res.partial) s += ' · ناقص (زمان زیاد شد؛ «به‌روزرسانی» را بزن)';
    status.textContent = s;

    renderFilters();
    renderList();
  };

  refreshBtn.addEventListener('click', () => load(true));
  search.addEventListener('input', renderList);
  renderFilters();
  load(false);
}

function renderMedia(m) {
  const kind = m.media_kind || m.media || 'none';
  if (m.media_url) {
    if (kind === 'photo') return el('img', { class: 'msg-photo', src: m.media_url, loading: 'lazy', onclick: () => lightbox(m.media_url) });
    if (kind === 'sticker') return el('img', { class: 'msg-photo msg-sticker', src: m.media_url, loading: 'lazy' });
    if (kind === 'gif') return el('video', { class: 'msg-video', src: m.media_url, autoplay: 'autoplay', loop: 'loop', muted: 'muted', playsinline: 'playsinline' });
    if (kind === 'video') return el('video', { class: 'msg-video', src: m.media_url, controls: 'controls', preload: 'metadata' });
    if (kind === 'voice' || kind === 'audio') return el('audio', { class: 'msg-audio', src: m.media_url, controls: 'controls', preload: 'none' });
    // document or anything else with a file → download chip
    return el('a', { class: 'media-chip', href: m.media_url, target: '_blank', download: '' }, icon('file'), 'دانلود ' + mediaLabel(kind));
  }
  if (kind === 'location' && m.geo && m.geo.lat != null) {
    const url = 'https://www.google.com/maps?q=' + m.geo.lat + ',' + m.geo.long;
    return el('a', { class: 'media-chip', href: url, target: '_blank', rel: 'noopener' }, icon('pin'), (m.venue || 'موقعیت مکانی'));
  }
  if (kind === 'contact' && m.contact) {
    return el('div', { class: 'media-chip' }, icon('user'), (m.contact.name || '') + (m.contact.phone ? ' · ' + m.contact.phone : ''));
  }
  if (kind === 'poll') return el('div', { class: 'media-chip' }, icon('poll'), 'نظرسنجی');
  if (kind && kind !== 'none') return el('div', { class: 'media-chip' }, mediaIconNode(kind), mediaLabel(kind) + ' (دانلود نشد)');
  return null;
}

async function openHistoryInto(main, c) {
  main.innerHTML = '';
  const msgs = el('div', { class: 'chat-msgs' }, el('div', { class: 'loading' }, el('span', { class: 'spin' }), 'دریافت پیام‌ها...'));
  const input = el('input', { placeholder: 'پیام بنویس...', onkeydown: (e) => { if (e.key === 'Enter') doSend(); } });
  const sendBtn = el('button', { class: 'btn primary', onclick: () => doSend() }, icon('send'), 'ارسال');
  const composer = el('div', { class: 'composer' }, input, sendBtn);

  async function doSend() {
    const text = input.value.trim();
    if (!text) return;
    sendBtn.disabled = true;
    const res = await api('send_message', { key: route.key, peer: c.peer, text });
    sendBtn.disabled = false;
    if (!res.ok) return flash(res);
    input.value = '';
    const b = el('div', { class: 'bubble out' }, el('div', {}, text), el('div', { class: 'date' }, 'الان'));
    msgs.append(b);
    msgs.scrollTop = msgs.scrollHeight;
    input.focus();
  }

  main.append(
    el('div', { class: 'chat-head' },
      el('div', { class: 'avatar' }, initial(c.title)),
      el('div', {}, el('div', { style: 'font-weight:700' }, c.title),
        el('div', { class: 'muted', style: 'font-size:12px' }, kindLabel(c.kind) + (c.username ? ' · ' + c.username : '')))),
    msgs, composer);

  const r = await api('history', { key: route.key, peer: c.peer });
  msgs.innerHTML = '';
  if (!r.ok) { msgs.append(el('div', { class: 'chat-empty' }, icon('x', 'ico-svg'), r.error || 'خطا')); return; }
  if (!r.messages.length) { msgs.append(el('div', { class: 'chat-empty' }, icon('message', 'ico-svg'), 'پیامی نیست')); }

  r.messages.forEach((m) => {
    const b = el('div', { class: 'bubble ' + (m.out ? 'out' : 'in') });
    const media = renderMedia(m);
    if (media) b.append(media);
    if (m.text) b.append(el('div', {}, m.text));
    else if (!media) b.append(el('span', { class: 'muted' }, '[بدون متن]'));
    b.append(el('div', { class: 'date' }, m.date + ' · #' + m.id));
    msgs.append(b);
  });
  msgs.scrollTop = msgs.scrollHeight;
  input.focus();
}

function mediaIconNode(kind) {
  const map = { photo: 'image', video: 'video', voice: 'mic', audio: 'music', gif: 'film', sticker: 'smile', document: 'file', location: 'pin', contact: 'user', poll: 'poll' };
  return icon(map[kind] || 'file');
}

/* ---------- archive of genuinely deleted / view-once messages ---------- */
async function tabArchive(body, r) {
  let filter = 'all';
  const fbtns = el('div', { class: 'row' });
  const grid = el('div', { class: 'arch-grid' });

  const load = async () => {
    grid.innerHTML = '';
    grid.append(el('div', { class: 'loading' }, el('span', { class: 'spin' }), 'دریافت آرشیو...'));
    const res = await api('archive', { key: route.key, filter });
    grid.innerHTML = '';
    if (!res.ok) { grid.append(el('div', { class: 'empty' }, res.error || 'خطا')); return; }
    if (!res.items.length) {
      grid.append(el('div', { class: 'empty' }, 'هنوز پیام حذف‌شده‌ای ثبت نشده. از این لحظه به بعد، هر پیامی که در چت‌ها حذف یا یکبارمصرف باشد اینجا می‌آید.'));
      return;
    }
    res.items.forEach((it) => grid.append(archCard(it, load)));
  };
  const renderF = () => {
    fbtns.innerHTML = '';
    [['all', 'همه'], ['deleted', 'حذف‌شده'], ['view_once', 'یکبارمصرف'], ['media', 'فقط مدیا'], ['text', 'فقط متن']].forEach(([k, l]) =>
      fbtns.append(el('button', { class: 'btn small ' + (filter === k ? 'primary' : 'ghost'), onclick: () => { filter = k; renderF(); load(); } }, l)));
  };
  renderF();

  body.append(
    el('div', { class: 'row spread', style: 'margin-bottom:14px;align-items:flex-start;gap:14px' },
      el('div', { class: 'muted', style: 'max-width:55%;font-size:12.5px' }, 'فقط پیام‌هایی که واقعاً حذف شده‌اند یا مدیای یکبارمصرف بوده‌اند، به‌همراه فایل اصلی، اینجا به‌صورت دائمی نگه داشته می‌شوند.'),
      el('div', { class: 'row' }, fbtns,
        el('button', {
          class: 'btn danger small', onclick: () => confirmDialog('پاک‌سازی آرشیو', 'کل آرشیو حذف‌شده‌های این اکانت پاک شود؟ این عمل برگشت‌ناپذیر است.', async () => { flash(await api('archive_clear', { key: route.key })); load(); }, 'پاک کن')
        }, icon('trash'), 'پاک‌سازی کل'))),
    grid);
  load();
}

function archCard(it, reload) {
  const head = it.is_image
    ? el('img', { class: 'arch-thumb', src: it.media_url, loading: 'lazy', onclick: () => lightbox(it.media_url) })
    : el('div', {
        class: 'arch-thumb placeholder',
        style: it.has_media ? 'cursor:pointer' : '',
        onclick: it.has_media ? () => window.open(it.media_url, '_blank') : null
      }, mediaIconNode(it.media_type === 'none' ? (it.kind === 'view_once' ? 'image' : 'message') : it.media_type));

  const tags = el('div', { class: 'row', style: 'gap:6px' });
  tags.append(it.kind === 'view_once'
    ? el('span', { class: 'tag once' }, 'یکبارمصرف')
    : el('span', { class: 'tag del' }, icon('trash'), 'حذف‌شده'));
  tags.append(el('span', { class: 'tag' }, kindLabel(it.chat_kind) || 'چت'));

  const textNode = it.text
    ? el('div', { class: 'arch-text' }, it.text)
    : (it.special ? el('div', { class: 'arch-text muted' }, it.special) : el('div', { class: 'arch-text muted' }, '[بدون متن]'));

  return el('div', { class: 'arch-card' },
    head,
    el('div', { class: 'arch-body' },
      el('div', { class: 'arch-head' }, tags,
        el('button', {
          class: 'btn danger icon-btn small', title: 'حذف از آرشیو',
          onclick: () => { api('archive_delete', { key: route.key, archive_id: it.archive_id }).then((res) => { flash(res); reload && reload(); }); }
        }, icon('trash'))),
      textNode,
      el('div', { class: 'arch-meta' },
        el('span', {}, icon('user'), it.sender),
        el('span', {}, icon('message'), it.chat),
        el('span', {}, icon('trash'), 'حذف: ' + (it.deleted_at || '—')),
        it.has_media && !it.is_image ? el('span', {}, mediaIconNode(it.media_type), mediaLabel(it.media_type)) : null)
    ));
}

/* ---------- logs ---------- */
async function tabLogs(body, r) {
  let which = 'bot';
  const pre = el('pre', { class: 'log' }, 'در حال دریافت...');
  const load = async () => {
    pre.textContent = 'در حال دریافت...';
    const res = await api('log', { key: route.key, which });
    pre.textContent = res.ok ? (res.log || '(خالی)') : (res.error || 'خطا');
    pre.scrollTop = pre.scrollHeight;
  };
  const botBtn = el('button', { class: 'btn small primary', onclick: () => { which = 'bot'; botBtn.className = 'btn small primary'; qrBtn.className = 'btn small ghost'; load(); } }, 'لاگ بات');
  const qrBtn = el('button', { class: 'btn small ghost', onclick: () => { which = 'qr'; qrBtn.className = 'btn small primary'; botBtn.className = 'btn small ghost'; load(); } }, 'لاگ QR');

  body.append(el('div', { class: 'card' },
    el('div', { class: 'row spread', style: 'margin-bottom:12px' },
      el('div', { class: 'row' }, botBtn, qrBtn),
      el('div', { class: 'row' },
        el('button', { class: 'btn ghost small', onclick: load }, icon('refresh'), 'تازه‌سازی'),
        el('button', {
          class: 'btn danger small',
          onclick: () => confirmDialog('پاک‌سازی لاگ', 'محتوای این لاگ پاک شود؟', async () => { flash(await api('clear_log', { key: route.key, which })); load(); }, 'پاک کن')
        }, icon('trash'), 'پاک‌سازی'))),
    pre));
  load();
}

/* ---------- about ---------- */
function renderAbout(v) {
  v.innerHTML = '';
  v.append(el('div', { class: 'page-head' }, el('h1', {}, 'درباره')),
    el('div', { class: 'card', style: 'max-width:680px' },
      el('h3', {}, BOOT.title),
      el('p', { class: 'muted' }, 'پنل حرفه‌ای مدیریت چند اکانت یوزربات مبتنی بر MadelineProto. کنترل کامل قابلیت‌ها (بازیابی پیام حذف/ویرایش‌شده، ساعت پروفایل، ارسال خودکار) مستقیماً از همین پنل.'),
      el('p', { class: 'muted' }, 'API ID: ' + BOOT.apiId),
      el('p', { class: 'muted', style: 'font-size:12px' }, 'این پنل را بدون رمز قوی و HTTPS روی اینترنت عمومی قرار نده.')
    ));
}

/* ---------- boot ---------- */
function startAutoRefresh() {
  clearInterval(refreshTimer);
  refreshTimer = setInterval(() => { if (route.view === 'dashboard') renderDashboard($('view')); }, 9000);
}

document.querySelectorAll('#nav button').forEach((b) => b.addEventListener('click', () => go(b.dataset.view)));
$('logoutBtn').addEventListener('click', async () => { await api('logout'); location.reload(); });
function applyTheme(t) {
  if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  else document.documentElement.removeAttribute('data-theme');
}
$('themeToggle').addEventListener('click', () => {
  const next = document.documentElement.getAttribute('data-theme') === 'dark' ? '' : 'dark';
  applyTheme(next);
  localStorage.setItem('ubcc_theme', next);
});
applyTheme(localStorage.getItem('ubcc_theme'));

render();
startAutoRefresh();

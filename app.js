'use strict';
/* Salah: single-file PWA. State comes from /api/state; all rules run on the server. */

// ---------- tiny helpers ----------
const ls = {
  get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  del(k) { try { localStorage.removeItem(k); } catch (e) { /* ignore */ } },
};
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const $ = (s, el = document) => el.querySelector(s);
const nowTs = () => Math.floor(Date.now() / 1000);
const ICON = {
  check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
  x: '<path d="M6 6l12 12M18 6L6 18"/>',
  today: '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/><circle cx="12" cy="12" r="3.5"/>',
  tasks: '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M3.5 6l1.2 1.2L7 5M3.5 12l1.2 1.2L7 11M3.5 18l1.2 1.2L7 17"/>',
  chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/>',
};
const icon = (n, cls = '') => `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICON[n] || ''}</svg>`;

let token = ls.get('tok');
let eventKey = ls.get('ek');
let S = null;            // server state
let offline = false;
let authInfo = null;     // {configured, vapid}
let toastTimer = null;

function toast(msg) {
  let t = $('.toast');
  if (!t) { t = document.createElement('div'); t.className = 'toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
  t.textContent = msg;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.remove(), 2600);
}

// ---------- api with a small offline outbox ----------
async function api(path, body, method) {
  const r = await fetch('/api' + path, {
    method: method || (body ? 'POST' : 'GET'),
    headers: { 'Content-Type': 'application/json', 'X-Auth': token || '' },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (r.status === 401 && token) { token = null; ls.del('tok'); render(); throw new Error('signed out'); }
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(j.error || 'request failed');
  return j;
}

const outbox = () => { try { return JSON.parse(ls.get('outbox') || '[]'); } catch (e) { return []; } };
async function flushOutbox() {
  const q = outbox();
  if (!q.length) return;
  const rest = [];
  for (const it of q) { try { await api(it.path, it.body); } catch (e) { if (!navigator.onLine) rest.push(it); } }
  ls.set('outbox', JSON.stringify(rest));
}
async function act(path, body, msg) {
  try {
    const r = await api(path, body);
    if (msg) toast(msg);
    await load();
    return r;
  } catch (e) {
    if (!navigator.onLine || e instanceof TypeError) {
      ls.set('outbox', JSON.stringify([...outbox(), { path, body }]));
      toast('Offline. Saved, will sync.');
    } else toast(e.message);
  }
}

async function load() {
  try {
    await flushOutbox();
    S = await api('/state');
    ls.set('state', JSON.stringify(S));
    offline = false;
  } catch (e) {
    if (!token) return;
    const c = ls.get('state');
    if (c) S = JSON.parse(c);
    offline = true;
  }
  render();
}

// ---------- time formatting ----------
const tz = () => (S && S.settings && S.settings.tz) || 'Asia/Riyadh';
const fmtT = (ts) => ts ? new Intl.DateTimeFormat('en-GB', { timeZone: tz(), hour: '2-digit', minute: '2-digit' }).format(new Date(ts * 1000)) : '';
const fmtDate = (ts) => new Intl.DateTimeFormat('en-GB', { timeZone: tz(), weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(ts * 1000));
function fmtDur(sec) {
  sec = Math.max(0, Math.round(sec));
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
  return h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
}
const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// ---------- views ----------
function dot(p) {
  if (p.status === 'prayed') return `<span class="dot ok">${icon('check')}</span>`;
  if (p.status === 'qada') return `<span class="dot qada">${icon('check')}</span>`;
  if (p.status === 'missed') return `<span class="dot bad">${icon('x')}</span>`;
  return '<span class="dot"></span>';
}
const statusChip = (p) => ({ prayed: p.masjid ? '<span class="chip ok">masjid</span>' : '<span class="chip ok">on time</span>', qada: '<span class="chip warn">qada</span>', missed: '<span class="chip bad">missed</span>' }[p.status] || '');

function installBanner() {
  const standalone = window.navigator.standalone || matchMedia('(display-mode: standalone)').matches;
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
  if (standalone || !ios) return '';
  return '<div class="banner">To get notifications: tap Share, then Add to Home Screen, and open Salah from the new icon.</div>';
}

function viewToday() {
  const s = S, now = nowTs();
  const cur = s.carry ? { carry: true, label: s.carry.label, key: 'isha' } : s.prayers.find((p) => p.phase === 'now' && p.status === 'pending');
  const next = s.next;
  const left = next ? next.at - now : 0;
  const prayed = s.prayers.filter((p) => p.status === 'prayed' || p.status === 'qada').length;
  const done = s.tasks.filter((t) => t.done && !t.optional).length, total = s.tasks.filter((t) => !t.optional).length;
  const last = s.last_pushes && s.last_pushes[0];
  return `
  <header class="row between">
    <div><h1>${esc(fmtDate(s.now))}</h1><small>${s.off_day ? 'Off day' : 'Office day'} · ${prayed}/5 prayers · ${done}/${total} goals</small></div>
    ${offline ? '<span class="chip warn">offline</span>' : ''}
  </header>
  ${installBanner()}
  <section class="card hero" style="margin-top:14px">
    <small>${esc(next ? (next.tomorrow ? 'Tomorrow ' : '') + next.label : '')} in</small>
    <div class="big" id="countdown">${fmtDur(left)}</div>
    <small>${esc(next ? fmtT(next.at) : '')}</small>
  </section>
  ${cur ? `<section class="card alert stack" style="margin-top:10px">
    <div><strong>Did you pray ${esc(cur.label)}?</strong></div>
    <div class="btns">
      <button class="btn primary" data-act="prayed" data-p="${cur.key}" data-m="1">Yes, in the masjid</button>
      <button class="btn" data-act="prayed" data-p="${cur.key}" data-m="0">Yes, at home</button>
      <button class="btn amber" data-act="notyet" data-p="${cur.key}">Not yet. Ask me again</button>
    </div></section>` : ''}
  <h2>Prayers</h2>
  <section class="card">${s.prayers.map((p) => `
    <a class="prayer ${p.phase === 'now' && p.status === 'pending' ? 'now' : ''}" href="#/pray/${p.key}">
      ${dot(p)}<span class="grow"><span class="name">${esc(p.label)}</span> ${statusChip(p)}</span>
      <span class="time">${fmtT(p.start)}</span></a>`).join('')}
  </section>
  <h2>Sleep and body</h2>
  <section class="card stack">
    <div class="btns two">
      <button class="btn ${s.day.up_at ? '' : 'primary'}" data-go="#/up">${s.day.up_at ? 'Up at ' + fmtT(s.day.up_at) : 'I am up'}</button>
      <button class="btn ${s.night.bed_at ? '' : 'ghost'}" data-go="#/bed">${s.night.bed_at ? 'In bed ' + fmtT(s.night.bed_at) : 'I am in bed'}</button>
    </div>
    <div class="row"><label for="steps" class="grow" style="margin:0">Steps today (goal ${Number(s.settings.goal_steps).toLocaleString()})</label>
      <input id="steps" type="number" inputmode="numeric" value="${s.day.steps || ''}" placeholder="0" style="width:110px">
      <button class="btn small" data-act="steps">Save</button></div>
    <div class="bar ${s.day.steps >= s.settings.goal_steps ? '' : 'warn'}"><i style="width:${Math.min(100, Math.round(100 * s.day.steps / s.settings.goal_steps))}%"></i></div>
    ${weightRow()}
  </section>
  <h2>Today's goals</h2>
  <section class="card">${taskList(false)}<p style="margin:10px 0 0"><a href="#/tasks">All goals and reminders</a></p></section>
  ${weeklyCard()}
  ${last ? `<h2>Last reminder</h2><section class="card"><div class="quote">${esc(last.body)}</div></section>` : ''}`;
}

function weightRow() {
  const w = S.weight;
  const prog = w.first != null && w.latest != null ? `Start ${w.first} kg, latest ${w.latest} kg, change ${(w.latest - w.first).toFixed(1)} kg` : 'No weight logged yet.';
  return `<div class="row"><label for="weight" class="grow" style="margin:0">Weight (kg)</label>
    <input id="weight" type="number" step="0.1" inputmode="decimal" value="${S.day.weight || ''}" style="width:110px">
    <button class="btn small" data-act="weight">Save</button></div><small>${esc(prog)}</small>`;
}

function taskList(withNotYet) {
  return S.tasks.map((t) => `
    <div class="task ${t.done ? 'done' : ''} ${t.optional ? 'optional' : ''}">
      <button class="check ${t.done ? 'on' : ''}" data-act="task" data-k="${esc(t.key)}" aria-label="${t.done ? 'Mark not done' : 'Mark done'}: ${esc(t.label)}">${t.done ? icon('check') : ''}</button>
      <span class="grow"><span class="label">${esc(t.label)}</span>${t.time ? `<br><small>${esc(t.time)}</small>` : t.optional ? '<br><small>optional</small>' : ''}</span>
      ${withNotYet && !t.done && !t.optional && !t.auto ? `<button class="btn small ghost" data-act="tasknotyet" data-k="${esc(t.key)}">Not yet</button>` : ''}
    </div>`).join('');
}

function weeklyCard() {
  const w = S.weekly;
  const line = (k, label) => `<div class="kv"><span>${label}</span><span class="muted">${w[k] ? esc(w[k].text) : 'not set'}</span></div>`;
  return `<h2>This week</h2><section class="card stack">${line('seerah', 'Seerah')}${line('hadith', 'Hadith')}${line('tafseer', 'Tafseer of Rahman')}
    <a href="#/weekly">Update</a></section>`;
}

function viewPray(key) {
  const p = S.prayers.find((x) => x.key === key);
  if (!p) return '<p>Unknown prayer.</p>';
  const useCarry = key === 'isha' && S.carry;
  const label = useCarry ? S.carry.label : p.label;
  const status = useCarry ? 'pending' : p.status;
  return `
  <header><a href="#/today">&larr; Today</a><h1>${esc(label)}</h1><small>${fmtT(p.start)} to ${fmtT(p.end)}</small></header>
  <section class="card stack" style="margin-top:14px">
    <div><strong>${status === 'prayed' || status === 'qada' ? 'Marked as prayed.' : status === 'missed' ? 'The time has ended. Pray it as qada and mark it.' : 'Did you pray?'}</strong> ${statusChip(p)}</div>
    <div class="btns">
      <button class="btn primary" data-act="prayed" data-p="${key}" data-m="1" ${useCarry ? 'data-carry="1"' : ''}>Yes, in the masjid</button>
      <button class="btn" data-act="prayed" data-p="${key}" data-m="0" ${useCarry ? 'data-carry="1"' : ''}>Yes, at home</button>
      ${status === 'pending' ? `<button class="btn amber" data-act="notyet" data-p="${key}" ${useCarry ? 'data-carry="1"' : ''}>Not yet. Ask me again</button>` : ''}
      ${status === 'prayed' || status === 'qada' ? `<button class="btn danger" data-act="unprayed" data-p="${key}">Undo</button>` : ''}
    </div>
  </section>`;
}

function viewUp() {
  const d = S.day;
  return `
  <header><a href="#/today">&larr; Today</a><h1>Wake up</h1></header>
  <section class="card stack" style="margin-top:14px">
    ${d.up_at ? `<p>You said you were up at <strong>${fmtT(d.up_at)}</strong>.${d.wake_pending ? ' Confirm you are still awake.' : ''}</p>` : '<p>Feet on the floor first. Then tap.</p>'}
    <button class="btn primary" data-act="up">${d.up_at ? 'Yes, I am awake' : 'I am up'}</button>
    <button class="btn ghost" data-go="#/alarm">Fajr alarm in the app</button>
  </section>`;
}

function viewBed() {
  return `
  <header><a href="#/today">&larr; Today</a><h1>Going to sleep</h1></header>
  <section class="card stack" style="margin-top:14px">
    <p>Phone down, wudu done, dhikr of the night. Then tap.</p>
    <button class="btn primary" data-act="bed">${S.night.bed_at ? 'In bed since ' + fmtT(S.night.bed_at) : 'I am in bed'}</button>
    <small>Phone opened at night so far: ${S.night.phone_uses} time(s).</small>
  </section>`;
}

function viewTasks() {
  return `<header><h1>Goals</h1><small>Reminders come one at a time, from the time shown.</small></header>
  <section class="card" style="margin-top:14px">${taskList(true)}</section>
  <p style="margin-top:12px"><a href="#/settings">Edit goals and times</a></p>`;
}

function viewWeekly() {
  const w = S.weekly;
  const f = (k, label, hint) => `
    <section class="card stack"><strong>${label}</strong>
      <small>Now: ${w[k] ? esc(w[k].text) + ' (set ' + esc(w[k].date_set) + ')' : 'not set'}</small>
      <textarea id="wk-${k}" placeholder="${hint}"></textarea>
      <button class="btn" data-act="weekly" data-k="${k}">Save ${label}</button></section>`;
  return `<header><a href="#/today">&larr; Today</a><h1>This week</h1><small>Thursday: Seerah and Hadith. Tuesday after Fajr: Tafseer.</small></header>
  <div class="stack" style="margin-top:14px">${f('seerah', 'Seerah part', 'e.g. The Hijra to Madinah')}${f('hadith', 'Hadith', 'e.g. Nawawi 14')}${f('tafseer', 'Tafseer of Rahman', 'e.g. Verses 1 to 13')}</div>`;
}

let reviewData = null, reviewPeriod = 'week';
function viewReview() {
  const r = reviewData;
  const pct = (v) => (v == null ? '-' : v + '%');
  const bar = (v) => `<div class="bar ${v >= 80 ? '' : v >= 50 ? 'warn' : 'bad'}"><i style="width:${v || 0}%"></i></div>`;
  let body = '<p class="muted">Loading...</p>';
  if (r && r.period === reviewPeriod) {
    const pp = r.prayers.per_prayer;
    const maxSteps = Math.max(r.steps.goal, ...r.steps.series.map((x) => x.steps || 0));
    body = `
    <section class="card stack">
      <div class="row between"><strong>Prayers on time</strong><span class="big" style="font-size:1.6rem">${pct(r.prayers.on_time_pct)}</span></div>
      <small>${r.prayers.total.prayed} on time, ${r.prayers.total.qada} qada, ${r.prayers.total.missed} missed, ${r.prayers.total.masjid} in the masjid</small>
      ${Object.keys(pp).map((k) => `<div class="row"><span style="width:78px;text-transform:capitalize">${k}</span><div class="grow">${bar(pp[k].pct)}</div><span style="width:44px;text-align:right">${pct(pp[k].pct)}</span></div>`).join('')}
    </section>
    <h2>Goals</h2>
    <section class="card stack">${r.tasks.map((t) => `<div class="row"><span style="width:130px">${esc((S.tasks.find((x) => x.key === t.key) || { label: t.key }).label)}</span><div class="grow">${bar(t.pct)}</div><span style="width:44px;text-align:right">${t.pct}%</span></div>`).join('') || '<small>No data yet.</small>'}</section>
    <h2>Steps</h2>
    <section class="card stack">
      <div class="row between"><span>Average ${r.steps.avg == null ? '-' : r.steps.avg.toLocaleString()}</span><span>${r.steps.days_hit_goal} day(s) at ${r.steps.goal.toLocaleString()}</span></div>
      <div class="spark" aria-hidden="true">${r.steps.series.map((x) => `<i class="${(x.steps || 0) < r.steps.goal ? 'low' : ''}" style="height:${Math.max(3, Math.round(100 * (x.steps || 0) / maxSteps))}%"></i>`).join('')}</div>
    </section>
    <h2>Sleep and nights</h2>
    <section class="card kv"><span>Average wake-up</span><span>${r.sleep.avg_up || '-'}</span><span>Average bedtime</span><span>${r.sleep.avg_bed || '-'}</span>
      <span>Phone opened at night</span><span>${r.sleep.phone_uses_at_night}</span><span>Fell back asleep after waking</span><span>${r.sleep.back_asleep}</span><span>Tahajjud days</span><span>${r.tahajjud_days}</span></section>
    ${r.weight.length ? `<h2>Weight</h2><section class="card kv">${r.weight.map((w) => `<span>${esc(w.date)}</span><span>${w.kg} kg</span>`).join('')}</section>` : ''}
    <small class="muted">Notifications sent: ${r.pushes.total} (${r.pushes.per_day} a day).</small>`;
  }
  return `<header class="row between"><h1>Review</h1>
    <div class="row"><button class="btn small ${reviewPeriod === 'week' ? 'primary' : ''}" data-act="period" data-p="week">Week</button><button class="btn small ${reviewPeriod === 'month' ? 'primary' : ''}" data-act="period" data-p="month">Month</button></div></header>
    <small>${r ? esc(r.from) + ' to ' + esc(r.to) : ''}</small><div style="margin-top:12px">${body}</div>`;
}

// ---- settings ----
let pushState = 'unknown';
async function refreshPushState() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) { pushState = 'unsupported'; return; }
  if (Notification.permission === 'denied') { pushState = 'denied'; return; }
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();
  pushState = Notification.permission === 'granted' && sub ? 'on' : 'off';
}
const b64u = (s) => Uint8Array.from(atob((s + '='.repeat((4 - (s.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
async function enablePush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) throw new Error('Not supported here. On iPhone, add the app to the Home Screen and open it from there.');
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') throw new Error('Notifications were not allowed. Turn them on in Settings > Notifications > Salah.');
  const reg = await navigator.serviceWorker.ready;
  const info = authInfo || await (await fetch('/api/status')).json();
  let sub = await reg.pushManager.getSubscription();
  if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64u(info.vapid) });
  await api('/push/subscribe', sub.toJSON());
  await refreshPushState();
}
async function syncPush() { // keep the server's copy of this device's subscription fresh
  try {
    if (!token || !('serviceWorker' in navigator) || Notification.permission !== 'granted') return;
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    if (sub) await api('/push/subscribe', sub.toJSON());
  } catch (e) { /* retry next open */ }
}

function viewSettings() {
  const st = S.settings;
  const base = location.origin + '/api/event?k=' + (eventKey || 'YOUR_KEY');
  const pushTxt = { on: 'On for this phone', off: 'Not enabled yet', denied: 'Blocked in iPhone settings', unsupported: 'Not available in this browser', unknown: '...' }[pushState];
  return `
  <header><h1>Settings</h1></header>
  <h2>Notifications</h2>
  <section class="card stack">
    <div class="row between"><span>Push notifications</span><span class="chip ${pushState === 'on' ? 'ok' : 'warn'}">${pushTxt}</span></div>
    <small>Devices registered on the server: ${S.subs}</small>
    <div class="btns two"><button class="btn primary" data-act="enablepush">Enable</button><button class="btn" data-act="testpush">Send test</button></div>
  </section>
  <h2>Location and prayer times</h2>
  <section class="card stack">
    <small>Umm Al-Qura method. Location is set ${st.location_set === '1' ? 'from your phone' : '<strong>to Makkah until you set it</strong>'}. Time zone: ${esc(st.tz)}.</small>
    <button class="btn" data-act="locate">Use my current location</button>
    <div class="toggle"><span>Hanafi Asr (later time)</span><input type="checkbox" id="hanafi" ${st.asr_factor === '2' ? 'checked' : ''}></div>
    <div class="toggle"><span>Remind me to leave for the masjid</span><input type="checkbox" id="masjid" ${st.masjid_on === '1' ? 'checked' : ''}></div>
  </section>
  <h2>Schedule</h2>
  <section class="card">
    <label for="office_start">Office starts</label><input id="office_start" type="time" value="${esc(st.office_start)}">
    <label for="office_end">Office ends</label><input id="office_end" type="time" value="${esc(st.office_end)}">
    <label for="office_wake">Must be up by (office days)</label><input id="office_wake" type="time" value="${esc(st.office_wake)}">
    <label for="night_start">Bedtime reminders start</label><input id="night_start" type="time" value="${esc(st.night_start)}">
    <label for="pin_every_min">Re-send the Today notification every (minutes)</label><input id="pin_every_min" type="number" inputmode="numeric" min="15" value="${esc(st.pin_every_min)}">
    <label for="goal_steps">Daily steps goal</label><input id="goal_steps" type="number" inputmode="numeric" value="${esc(st.goal_steps)}">
    <label for="norepeat_days">Do not repeat a quote for (days)</label><input id="norepeat_days" type="number" inputmode="numeric" value="${esc(st.norepeat_days)}">
    <p style="margin-top:12px"><button class="btn" data-act="savesched">Save schedule</button></p>
  </section>
  <h2>Goals and reminder times</h2>
  <section class="card stack" id="taskcfg">${taskEditor()}</section>
  <h2>Shortcuts (steps, Sleep Focus, night phone use)</h2>
  <section class="card stack">
    <small>Shortcuts send events here with a POST and a JSON body. Keep this link private.</small>
    <div class="mono">${esc(base)}</div>
    <button class="btn small" data-act="copy" data-v="${esc(base)}">Copy link</button>
    <small>Bodies: <code>{"type":"steps","value":6000}</code> &nbsp; <code>{"type":"phone_use"}</code> &nbsp; <code>{"type":"sleep_focus"}</code></small>
    <div class="mono">${esc(location.origin + '/api/fajr.json?k=' + (eventKey || 'YOUR_KEY'))}</div>
    <small>That second link returns tomorrow's Fajr time for the nightly alarm.</small>
  </section>
  <h2>Account</h2>
  <section class="card stack"><button class="btn danger" data-act="logout">Sign out of this phone</button>
    <small>Server time zone: ${esc(st.tz)} · App v1</small></section>`;
}

function taskEditor() {
  const rows = S.tasks_cfg.map((t, i) => {
    if (t.auto || t.optional) return `<div class="row between"><span>${esc(t.label)}</span><small>${t.auto ? 'automatic' : 'optional'}</small></div>`;
    return `<div class="card stack" data-task="${i}" style="background:var(--bg)">
      <input type="text" data-f="label" value="${esc(t.label)}" aria-label="Goal name">
      <div class="row"><div class="grow"><label>Office days</label><input type="time" data-f="office" value="${esc(t.office || '')}"></div>
      <div class="grow"><label>Off days</label><input type="time" data-f="off" value="${esc(t.off || '')}"></div></div>
      <div class="toggle"><span>On</span><input type="checkbox" data-f="enabled" ${t.enabled === false ? '' : 'checked'}></div></div>`;
  }).join('');
  return rows + '<button class="btn" data-act="savetasks">Save goals</button><button class="btn ghost" data-act="addtask">Add a goal</button>';
}

// ---------- Fajr alarm in the app ----------
const alarm = { ctx: null, buf: null, bounds: [0, 0], src: null, armed: false, ringing: false, target: 0, timer: null, wake: null };
async function loadAlarm() {
  if (alarm.buf) return;
  alarm.ctx = alarm.ctx || new (window.AudioContext || window.webkitAudioContext)();
  const ab = await (await fetch('/fajr.mp3')).arrayBuffer();
  alarm.buf = await alarm.ctx.decodeAudioData(ab);
  const d = alarm.buf.getChannelData(0), th = 0.002;
  let a = 0, b = d.length - 1;
  while (a < d.length && Math.abs(d[a]) < th) a++;
  while (b > a && Math.abs(d[b]) < th) b--;
  alarm.bounds = [a / alarm.buf.sampleRate, (b + 1) / alarm.buf.sampleRate]; // loop exactly from first to last sound: no gap
}
function startRing() {
  stopRing();
  alarm.src = alarm.ctx.createBufferSource();
  alarm.src.buffer = alarm.buf;
  alarm.src.loop = true;
  [alarm.src.loopStart, alarm.src.loopEnd] = alarm.bounds;
  const g = alarm.ctx.createGain();
  g.gain.value = 1;
  alarm.src.connect(g).connect(alarm.ctx.destination);
  alarm.src.start(0, alarm.bounds[0]);
  alarm.ringing = true;
}
function stopRing() {
  try { if (alarm.src) alarm.src.stop(); } catch (e) { /* already stopped */ }
  alarm.src = null;
  alarm.ringing = false;
}
async function armAlarm(testNow) {
  try { if (navigator.audioSession) navigator.audioSession.type = 'playback'; } catch (e) { /* older iOS */ }
  await loadAlarm();
  await alarm.ctx.resume();
  try { alarm.wake = await navigator.wakeLock.request('screen'); } catch (e) { /* not supported */ }
  const now = nowTs();
  const f = S.prayers[0].start;
  alarm.target = testNow ? now : (f > now ? f : S.tomorrow_fajr);
  alarm.armed = true;
  clearInterval(alarm.timer);
  alarm.timer = setInterval(tickAlarm, 500);
  tickAlarm();
}
function tickAlarm() {
  if (alarm.armed && !alarm.ringing && nowTs() >= alarm.target) startRing();
  const el = $('.alarm');
  if (!el) return;
  el.classList.toggle('ring', alarm.ringing);
  const c = $('#alarm-count');
  if (c) c.textContent = alarm.ringing ? 'FAJR' : fmtDur(alarm.target - nowTs());
}
async function disarmAlarm(markUp) {
  stopRing();
  alarm.armed = false;
  clearInterval(alarm.timer);
  try { if (alarm.wake) await alarm.wake.release(); } catch (e) { /* ignore */ }
  if (markUp) await act('/up', {}, 'Good. Now wudu and go.');
}
function viewAlarm() {
  if (alarm.armed) {
    return `<div class="alarm ${alarm.ringing ? 'ring' : ''}">
      <small>${alarm.ringing ? 'Wake up' : 'Fajr alarm armed for ' + fmtT(alarm.target)}</small>
      <div class="big" id="alarm-count">${alarm.ringing ? 'FAJR' : fmtDur(alarm.target - nowTs())}</div>
      ${alarm.ringing ? '<button class="btn primary" data-act="alarmup" style="min-height:72px;font-size:1.3rem">I am up</button>' : '<small>Keep the phone plugged in with the screen on.<br>Silent switch off, volume up.</small><button class="btn ghost" data-act="alarmoff">Cancel alarm</button>'}
    </div>`;
  }
  return `<header><a href="#/today">&larr; Today</a><h1>Fajr alarm in the app</h1></header>
  <section class="card stack" style="margin-top:14px">
    <p>Plays your recording on a loop with no gap until you tap. It only works while this page is open, the screen is on and the phone is plugged in. Your phone's Clock alarm (set by the nightly Shortcut) is the main one. This is the second.</p>
    <button class="btn primary" data-act="arm">Arm for the next Fajr (${fmtT(S.prayers[0].start > nowTs() ? S.prayers[0].start : S.tomorrow_fajr)})</button>
    <button class="btn" data-act="armtest">Test the sound now</button>
    <small>Before tonight: set the volume, switch the silent switch off, and keep Do Not Disturb from muting the Clock.</small>
  </section>`;
}

// ---------- auth view ----------
function viewAuth() {
  const claim = authInfo && !authInfo.configured;
  return `<header style="margin-top:30px"><h1>Salah</h1><p class="muted">${claim ? 'First time here. Choose a passcode (6+ characters). It can be set only once.' : 'Enter your passcode.'}</p></header>
  <section class="card stack"><label for="pass">Passcode</label><input id="pass" type="password" autocomplete="${claim ? 'new-password' : 'current-password'}">
  <button class="btn primary" data-act="${claim ? 'claim' : 'login'}">${claim ? 'Set passcode' : 'Open'}</button></section>`;
}

// ---------- router ----------
const TABS = [['#/today', 'Today', 'today'], ['#/tasks', 'Goals', 'tasks'], ['#/review', 'Review', 'chart'], ['#/settings', 'Settings', 'gear']];
function render() {
  const app = $('#app');
  if (!token) { app.innerHTML = viewAuth(); return; }
  if (!S) { app.innerHTML = '<p class="muted" style="margin-top:40px">Loading...</p>'; return; }
  const h = location.hash || '#/today';
  const [, route, arg] = h.replace(/\?.*$/, '').split('/');
  const y = window.scrollY;
  let html;
  switch (route) {
    case 'pray': html = viewPray(arg); break;
    case 'up': html = viewUp(); break;
    case 'bed': html = viewBed(); break;
    case 'tasks': html = viewTasks(); break;
    case 'weekly': html = viewWeekly(); break;
    case 'review': html = viewReview(); break;
    case 'settings': html = viewSettings(); break;
    case 'alarm': html = viewAlarm(); break;
    default: html = viewToday();
  }
  const keep = route === 'settings' ? document.activeElement && document.activeElement.id : null;
  app.innerHTML = html + (route === 'alarm' && alarm.armed ? '' : `<nav class="tabbar" aria-label="Main">${TABS.map(([href, label, ic]) => `<a href="${href}" ${h.startsWith(href) ? 'aria-current="page"' : ''}>${icon(ic)}<span>${label}</span></a>`).join('')}</nav>`);
  if (keep) { const el = document.getElementById(keep); if (el) el.focus(); }
  window.scrollTo(0, y);
  if (route === 'review' && (!reviewData || reviewData.period !== reviewPeriod)) loadReview();
  if (route === 'settings') refreshPushState().then(() => { const c = $('.chip.warn, .chip.ok'); /* state chip refresh handled on next render */ });
}

async function loadReview() {
  try { reviewData = await api('/review?period=' + reviewPeriod); render(); } catch (e) { /* offline */ }
}

// ---------- events ----------
document.addEventListener('click', async (e) => {
  const go = e.target.closest('[data-go]');
  if (go) { location.hash = go.dataset.go; return; }
  const b = e.target.closest('[data-act]');
  if (!b) return;
  const a = b.dataset.act;
  const dateFor = (p, carry) => (carry && S.carry ? S.carry.date : S.date);
  switch (a) {
    case 'claim': case 'login': {
      const pass = $('#pass').value;
      try {
        const r = await fetch('/api/' + a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ passcode: pass }) });
        const j = await r.json();
        if (!r.ok) { toast(j.error || 'Failed'); return; }
        token = j.token; eventKey = j.event_key; ls.set('tok', token); ls.set('ek', eventKey);
        await load(); syncPush();
        location.hash = '#/today';
      } catch (err) { toast('No connection'); }
      break;
    }
    case 'prayed':
      await act('/prayed', { prayer: b.dataset.p, masjid: b.dataset.m === '1', date: dateFor(b.dataset.p, b.dataset.carry) }, 'Alhamdulillah. Recorded.');
      if (location.hash.startsWith('#/pray')) location.hash = '#/today';
      break;
    case 'unprayed':
      await act('/prayed', { prayer: b.dataset.p, undo: true }, 'Undone.');
      break;
    case 'notyet':
      await act('/notyet', { prayer: b.dataset.p, date: dateFor(b.dataset.p, b.dataset.carry) }, 'I will ask again in 2 minutes.');
      if (location.hash.startsWith('#/pray')) location.hash = '#/today';
      break;
    case 'task': await act('/task/toggle', { key: b.dataset.k }); break;
    case 'tasknotyet': await act('/notyet', { kind: 'task', key: b.dataset.k }, 'I will remind you again soon.'); break;
    case 'up': await act('/up', {}, S.day.up_at ? 'Confirmed.' : 'Good. I will check on you in 4 minutes.'); location.hash = '#/today'; break;
    case 'bed': await act('/bed', {}, 'Good night.'); location.hash = '#/today'; break;
    case 'steps': await act('/event', { type: 'steps', value: Number($('#steps').value || 0) }, 'Steps saved.'); break;
    case 'weight': await act('/event', { type: 'weight', value: Number($('#weight').value) }, 'Weight saved.'); break;
    case 'weekly': {
      const k = b.dataset.k, text = $('#wk-' + k).value.trim();
      if (!text) { toast('Write something first'); return; }
      await act('/weekly', { kind: k, text }, 'Saved.');
      break;
    }
    case 'period': reviewPeriod = b.dataset.p; reviewData = null; render(); break;
    case 'enablepush':
      try { await enablePush(); toast('Notifications are on.'); await load(); } catch (err) { toast(err.message); }
      break;
    case 'testpush':
      try { const r = await api('/push/test', {}); toast(r.devices ? 'Sent. Look at your lock screen.' : 'No device registered yet. Tap Enable.'); } catch (err) { toast(err.message); }
      break;
    case 'locate':
      if (!navigator.geolocation) { toast('Location not available'); return; }
      navigator.geolocation.getCurrentPosition(async (p) => {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        await act('/settings', { lat: +p.coords.latitude.toFixed(4), lng: +p.coords.longitude.toFixed(4), tz: zone }, 'Location saved: ' + zone);
      }, () => toast('Location was not allowed'), { enableHighAccuracy: false, timeout: 15000 });
      break;
    case 'savesched': {
      const body = { asr_factor: $('#hanafi').checked ? 2 : 1, masjid_on: $('#masjid').checked ? 1 : 0 };
      for (const k of ['office_start', 'office_end', 'office_wake', 'night_start', 'pin_every_min', 'goal_steps', 'norepeat_days']) body[k] = $('#' + k).value;
      await act('/settings', body, 'Saved.');
      break;
    }
    case 'savetasks': {
      const cfg = S.tasks_cfg.map((t) => ({ ...t }));
      document.querySelectorAll('[data-task]').forEach((el) => {
        const t = cfg[+el.dataset.task];
        el.querySelectorAll('[data-f]').forEach((i) => { t[i.dataset.f] = i.type === 'checkbox' ? i.checked : i.value; });
      });
      await act('/tasks/config', { tasks: cfg }, 'Goals saved.');
      break;
    }
    case 'addtask': {
      const label = prompt('Name of the new goal');
      if (!label) return;
      const cfg = [...S.tasks_cfg, { key: 'c' + Date.now().toString(36), label, office: '19:00', off: '10:00', ctx: ['task'], enabled: true }];
      await act('/tasks/config', { tasks: cfg }, 'Added.');
      break;
    }
    case 'copy': try { await navigator.clipboard.writeText(b.dataset.v); toast('Copied'); } catch (err) { toast('Copy failed. Select the text instead.'); } break;
    case 'logout':
      try { await api('/logout', {}); } catch (err) { /* ignore */ }
      token = null; ls.del('tok'); ls.del('state'); S = null; render();
      break;
    case 'arm': await armAlarm(false); render(); break;
    case 'armtest': await armAlarm(true); render(); break;
    case 'alarmoff': await disarmAlarm(false); render(); break;
    case 'alarmup': await disarmAlarm(true); location.hash = '#/today'; break;
  }
});
document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.id === 'pass') { const b = $('[data-act=login],[data-act=claim]'); if (b) b.click(); } });
window.addEventListener('hashchange', render);
window.addEventListener('online', () => load());
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && token) { load(); syncPush(); } });
setInterval(() => { // keep the countdown live without hitting the server
  const c = $('#countdown');
  if (c && S && S.next) c.textContent = fmtDur(S.next.at - nowTs());
  if (S && S.next && S.next.at - nowTs() < -30 && navigator.onLine) load();
}, 15000);

// ---------- boot ----------
(async function boot() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
  try { authInfo = await (await fetch('/api/status')).json(); } catch (e) { authInfo = null; }
  if (S === null && ls.get('state') && token) { S = JSON.parse(ls.get('state')); offline = true; }
  render();
  if (token) { await load(); syncPush(); }
})();

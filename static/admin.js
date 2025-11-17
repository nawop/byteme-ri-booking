const BASE = new URL('./', window.location.href).pathname;

// --- Secret persistence ---
const secretInput = document.getElementById('adminSecret');
const saveBtn = document.getElementById('saveSecret');

if (secretInput && saveBtn) {
  secretInput.value = localStorage.getItem('byteme_admin_secret') || '';
  saveBtn.addEventListener('click', () => {
    localStorage.setItem('byteme_admin_secret', secretInput.value.trim());
    alert('Secret saved locally for this browser.');
    loadAll();
  });
}

function getSecret() {
  return localStorage.getItem('byteme_admin_secret') || '';
}

// --- Generic admin-aware API helper ---
async function api(endpoint, opts = {}) {
  const secret = getSecret();
  const method = (opts.method || 'GET').toUpperCase();

  let url = `${BASE}?api=${endpoint}`;
  const headers = { 'Content-Type': 'application/json' };
  let body = opts.body;

  if (method === 'GET') {
    // For admin endpoints, PHP accepts ?admin_secret=...
    if (secret) {
      url += `&admin_secret=${encodeURIComponent(secret)}`;
    }
  } else {
    // For POST/other, PHP expects admin_secret in JSON body
    let payload = {};
    if (body) {
      try {
        payload = JSON.parse(body);
      } catch {
        // if it's not JSON yet, ignore and overwrite
      }
    }
    if (secret) {
      payload.admin_secret = secret;
    }
    body = JSON.stringify(payload);
  }

  const res = await fetch(url, {
    credentials: 'same-origin',
    headers,
    method,
    body
  });

  const raw = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${raw}`);
  try { return JSON.parse(raw); }
  catch (e) { throw new Error(`Bad JSON: ${raw}`); }
}

// --- Tabs ---
document.querySelectorAll('.tab').forEach(b => {
  b.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    document.getElementById(b.dataset.tab).classList.add('active');
  });
});

// --- Pending bookings tab ---
async function loadBookings() {
  const el = document.getElementById('bookingsList');
  if (!el) return; // if the panel doesn't exist, skip

  el.innerHTML = 'Loading…';
  const data = await api('admin_state'); // contains bookings & activities
  const rows = data.pending_bookings || [];
  if (!rows.length) {
    el.innerHTML = '<div class="small">No pending bookings.</div>';
    return;
  }
  el.innerHTML = `
    <table class="table">
      <thead><tr>
        <th>ID</th><th>When</th><th>Activity</th><th>RI</th><th>Teacher</th><th></th>
      </tr></thead>
      <tbody>
        ${rows.map(b => `
          <tr data-id="${b.id}">
            <td>#${b.id}</td>
            <td>${b.starts_at} <div class="small">${b.created_at}</div></td>
            <td>${b.activity_name}</td>
            <td>${b.ri_name}</td>
            <td>${b.teacher_name} <div class="small">${b.teacher_email} • ${b.teacher_cycle}</div></td>
            <td class="inline">
              <button class="btn ok act-approve">Confirm</button>
              <button class="btn danger act-reject">Reject</button>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;

  el.querySelectorAll('.act-approve').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = parseInt(btn.closest('tr').dataset.id, 10);
      btn.disabled = true;
      try {
        await api('approve', { method: 'POST', body: JSON.stringify({ booking_id: id }) });
        await loadBookings();
      } catch (e) {
        alert(e.message || e);
        btn.disabled = false;
      }
    });
  });

  el.querySelectorAll('.act-reject').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = parseInt(btn.closest('tr').dataset.id, 10);
      btn.disabled = true;
      try {
        await api('reject', { method: 'POST', body: JSON.stringify({ booking_id: id }) });
        await loadBookings();
      } catch (e) {
        alert(e.message || e);
        btn.disabled = false;
      }
    });
  });
}

// --- Activities inline config tab ---
async function loadActivities() {
  const el = document.getElementById('activitiesList');
  if (!el) return;

  el.innerHTML = 'Loading…';
  const data = await api('admin_state');
  const rows = data.activities || [];
  if (!rows.length) { el.innerHTML = '<div class="small">No activities.</div>'; return; }

  el.innerHTML = `
    <table class="table">
      <thead><tr>
        <th>ID</th><th>RI</th><th>Cycle(s)</th><th>Name</th>
        <th>Dur (h)</th><th>Group</th><th>Published</th><th></th>
      </tr></thead>
      <tbody>
        ${rows.map(a => `
          <tr data-id="${a.id}">
            <td>#${a.id}</td>
            <td>${a.ri_name}</td>
            <td><input class="input in-cycles" value="${a.cycle || a.cycles || ''}" title="Comma-separated e.g. C3,C4"></td>
            <td><input class="input in-name" value="${a.name}"></td>
            <td><input class="input in-dur"  value="${a.duration_hours}" style="max-width:90px"></td>
            <td><input class="input in-group" value="${a.group_size}" style="max-width:90px"></td>
            <td>
              <select class="input in-pub" style="max-width:120px">
                <option value="1" ${a.is_published ? 'selected':''}>Yes</option>
                <option value="0" ${!a.is_published ? 'selected':''}>No</option>
              </select>
            </td>
            <td><button class="btn ok act-save">Save</button></td>
          </tr>
        `).join('')}
      </tbody>
    </table>
    <div class="small" style="margin-top:8px">
      Tipp: Setz z.B. "C1,C2" wann eng Aktivitéit fir méi Cycles gëllt.
    </div>
  `;

  el.querySelectorAll('.act-save').forEach(btn => {
    btn.addEventListener('click', async () => {
      const tr = btn.closest('tr');
      const id = parseInt(tr.dataset.id, 10);
      const payload = {
        id,
        name: tr.querySelector('.in-name').value.trim(),
        cycle: tr.querySelector('.in-cycles').value.trim(),
        duration_hours: parseFloat(tr.querySelector('.in-dur').value),
        group_size: parseInt(tr.querySelector('.in-group').value, 10),
        is_published: parseInt(tr.querySelector('.in-pub').value, 10)
      };
      btn.disabled = true;
      try {
        await api('activity_update', { method: 'POST', body: JSON.stringify(payload) });
        alert('Saved.');
      } catch (e) {
        alert(e.message || e);
      } finally {
        btn.disabled = false;
      }
    });
  });
}

// --- Quotas tab (view + edit) ---
async function loadQuotas() {
  const el = document.getElementById('quotasList');
  if (!el) return;

  el.innerHTML = 'Loading…';
  const data = await api('admin_quotas');
  if (!Array.isArray(data) || !data.length) {
    el.innerHTML = '<div class="small">Keng RI fonnt.</div>';
    return;
  }

  el.innerHTML = `
    <table class="table">
      <thead><tr>
        <th>RI</th>
        <th>Trimester Quota (h)</th>
        <th>Trimester verbraucht (h)</th>
        <th>Trimester Rescht (h)</th>
        <th>Joersquota (h)</th>
        <th>Joer verbraucht (h)</th>
        <th></th>
      </tr></thead>
      <tbody>
        ${data.map(r => `
          <tr data-id="${r.id}">
            <td>${r.name}</td>
            <td><input class="input q-trim" value="${r.quota_hours_trimester}" style="max-width:90px"></td>
            <td>${r.consumed_trimester}</td>
            <td>${r.remaining_trimester}</td>
            <td><input class="input q-year" value="${r.quota_hours_year}" style="max-width:90px"></td>
            <td>${r.consumed_year}</td>
            <td><button class="btn ok q-save">Save</button></td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;

  el.querySelectorAll('.q-save').forEach(btn => {
    btn.addEventListener('click', async () => {
      const tr = btn.closest('tr');
      const payload = {
        ri_id: parseInt(tr.dataset.id, 10),
        quota_hours_trimester: parseInt(tr.querySelector('.q-trim').value, 10) || 0,
        quota_hours_year: parseInt(tr.querySelector('.q-year').value, 10) || 0
      };
      btn.disabled = true;
      try {
        await api('admin_quotas_update', { method: 'POST', body: JSON.stringify(payload) });
        await loadQuotas();
      } catch (e) {
        alert(e.message || e);
      } finally {
        btn.disabled = false;
      }
    });
  });
}

// --- RI management tab (add / edit / delete RI) ---
async function loadRIs() {
  const el = document.getElementById('riList');
  if (!el) return;

  el.innerHTML = 'Loading…';
  const data = await api('admin_ri_list');
  if (!Array.isArray(data) || !data.length) {
    el.innerHTML = `
      <div class="small">Keng RI definéiert.</div>
      <button id="riAdd" class="btn" style="margin-top:8px;">+ Nei RI</button>
    `;
    const add = el.querySelector('#riAdd');
    if (add) {
      add.addEventListener('click', () => createNewRiAndReload());
    }
    return;
  }

  el.innerHTML = `
    <table class="table">
      <thead><tr>
        <th>ID</th><th>RI Numm</th><th>Trimester (h)</th><th>Joer (h)</th><th></th>
      </tr></thead>
      <tbody>
        ${data.map(ri => `
          <tr data-id="${ri.id}">
            <td>#${ri.id}</td>
            <td><input class="input ri-name" value="${ri.name}"></td>
            <td><input class="input ri-qtrim" value="${ri.quota_hours_trimester}" style="max-width:90px"></td>
            <td><input class="input ri-qyear" value="${ri.quota_hours_year}" style="max-width:90px"></td>
            <td class="inline">
              <button class="btn ok ri-save">Save</button>
              <button class="btn danger ri-del">Delete</button>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>
    <button id="riAdd" class="btn" style="margin-top:8px;">+ Nei RI</button>
  `;

  el.querySelectorAll('.ri-save').forEach(btn => {
    btn.addEventListener('click', async () => {
      const tr = btn.closest('tr');
      const id = parseInt(tr.dataset.id, 10);
      const payload = {
        id,
        name: tr.querySelector('.ri-name').value.trim(),
        quota_hours_trimester: parseInt(tr.querySelector('.ri-qtrim').value, 10) || 0,
        quota_hours_year: parseInt(tr.querySelector('.ri-qyear').value, 10) || 0
      };
      btn.disabled = true;
      try {
        await api('admin_ri_save', { method: 'POST', body: JSON.stringify(payload) });
        await loadRIs();
      } catch (e) {
        alert(e.message || e);
      } finally {
        btn.disabled = false;
      }
    });
  });

  el.querySelectorAll('.ri-del').forEach(btn => {
    btn.addEventListener('click', async () => {
      const tr = btn.closest('tr');
      const id = parseInt(tr.dataset.id, 10);
      if (!confirm('Dës RI läschen? All Aktivitéiten a Slots verbonne mam RI ginn och geläscht.')) return;
      btn.disabled = true;
      try {
        await api('admin_ri_delete', { method: 'POST', body: JSON.stringify({ id }) });
        await loadRIs();
      } catch (e) {
        alert(e.message || e);
      } finally {
        btn.disabled = false;
      }
    });
  });

  const add = el.querySelector('#riAdd');
  if (add) {
    add.addEventListener('click', () => createNewRiAndReload());
  }
}

async function createNewRiAndReload() {
  try {
    await api('admin_ri_save', {
      method: 'POST',
      body: JSON.stringify({
        id: 0,
        name: 'Nei RI',
        quota_hours_trimester: 0,
        quota_hours_year: 0
      })
    });
    await loadRIs();
  } catch (e) {
    alert(e.message || e);
  }
}

// --- Master loader ---
async function loadAll() {
  try {
    await Promise.all([
      loadBookings(),
      loadActivities(),
      loadQuotas(),
      loadRIs()
    ]);
  } catch (e) {
    alert('Load failed: ' + (e.message || e));
  }
}

loadAll();

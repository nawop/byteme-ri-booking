const BASE = (location.pathname.endsWith('/') ? location.pathname : location.pathname.replace(/\/[^/]+$/, '/'));

// Secret persistence
const secretInput = document.getElementById('adminSecret');
const saveBtn = document.getElementById('saveSecret');
secretInput.value = localStorage.getItem('byteme_admin_secret') || '';
saveBtn.addEventListener('click', ()=>{
  localStorage.setItem('byteme_admin_secret', secretInput.value);
  alert('Secret saved locally for this browser.');
  loadAll();
});

function hdrs(){
  const h = { 'Content-Type': 'application/json' };
  const s = localStorage.getItem('byteme_admin_secret') || '';
  if (s) {
    // send both to be compatible with your server
    h['Authorization'] = 'Bearer ' + s;
    h['X-Admin-Secret'] = s;
  }
  return h;
}

async function api(endpoint, opts={}){
  const url = `${BASE}?api=${endpoint}`;
  const res = await fetch(url, { credentials:'same-origin', headers: hdrs(), ...opts });
  const raw = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${raw}`);
  try { return JSON.parse(raw); } catch(e){ throw new Error(`Bad JSON: ${raw}`); }
}

// Tabs
document.querySelectorAll('.tab').forEach(b=>{
  b.addEventListener('click', ()=>{
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(x=>x.classList.remove('active'));
    b.classList.add('active');
    document.getElementById(b.dataset.tab).classList.add('active');
  });
});

async function loadBookings(){
  const el = document.getElementById('bookingsList');
  el.innerHTML = 'Loading…';
  const data = await api('admin_state'); // contains bookings & activities
  const rows = data.pending_bookings;
  if (!rows.length) { el.innerHTML = '<div class="small">No pending bookings.</div>'; return; }
  el.innerHTML = `
    <table class="table">
      <thead><tr>
        <th>ID</th><th>When</th><th>Activity</th><th>RI</th><th>Teacher</th><th></th>
      </tr></thead>
      <tbody>
        ${rows.map(b=>`
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
  el.querySelectorAll('.act-approve').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.closest('tr').dataset.id,10);
      btn.disabled=true;
      try{ await api('approve', { method:'POST', body:JSON.stringify({booking_id:id}) }); loadBookings(); }
      catch(e){ alert(e); btn.disabled=false; }
    });
  });
  el.querySelectorAll('.act-reject').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = parseInt(btn.closest('tr').dataset.id,10);
      btn.disabled=true;
      try{ await api('reject', { method:'POST', body:JSON.stringify({booking_id:id}) }); loadBookings(); }
      catch(e){ alert(e); btn.disabled=false; }
    });
  });
}

async function loadActivities(){
  const el = document.getElementById('activitiesList');
  el.innerHTML = 'Loading…';
  const data = await api('admin_state');
  const rows = data.activities;
  if (!rows.length) { el.innerHTML = '<div class="small">No activities.</div>'; return; }
  el.innerHTML = `
    <table class="table">
      <thead><tr>
        <th>ID</th><th>RI</th><th>Cycle(s)</th><th>Name</th><th>Dur (h)</th><th>Group</th><th>Published</th><th></th>
      </tr></thead>
      <tbody>
        ${rows.map(a=>`
          <tr data-id="${a.id}">
            <td>#${a.id}</td>
            <td>${a.ri_name}</td>
            <td><input class="input in-cycles" value="${a.cycle || a.cycles || ''}" title="Comma-separated e.g. C3,C4"></td>
            <td><input class="input in-name" value="${a.name}"></td>
            <td><input class="input in-dur"  value="${a.duration_hours}" style="max-width:90px"></td>
            <td><input class="input in-group" value="${a.group_size}" style="max-width:90px"></td>
            <td><select class="input in-pub" style="max-width:120px">
                  <option value="1" ${a.is_published ? 'selected':''}>Yes</option>
                  <option value="0" ${!a.is_published ? 'selected':''}>No</option>
                </select></td>
            <td><button class="btn ok act-save">Save</button></td>
          </tr>
        `).join('')}
      </tbody>
    </table>
    <div class="small" style="margin-top:8px">Tip: set cycles to "C1,C2" if you enabled multi-cycle display.</div>
  `;
  el.querySelectorAll('.act-save').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const tr = btn.closest('tr'); const id = parseInt(tr.dataset.id,10);
      const payload = {
        id,
        name: tr.querySelector('.in-name').value.trim(),
        cycle: tr.querySelector('.in-cycles').value.trim(),
        duration_hours: parseFloat(tr.querySelector('.in-dur').value),
        group_size: parseInt(tr.querySelector('.in-group').value,10),
        is_published: parseInt(tr.querySelector('.in-pub').value,10)
      };
      btn.disabled = true;
      try{
        await api('activity_update', { method:'POST', body:JSON.stringify(payload) });
        alert('Saved.');
      }catch(e){ alert(e); }
      finally{ btn.disabled=false; }
    });
  });
}

async function loadAll(){
  try{
    await Promise.all([loadBookings(), loadActivities()]);
  }catch(e){
    alert('Load failed: '+e.message);
  }
}
loadAll();

<?php
// admin.php — ByteMe Admin UI
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>ByteMe • Admin</title>
  <link rel="stylesheet" href="static/app.css"/>
  <style>
    .tabs{display:flex;gap:10px;margin:18px 0}
    .tabbtn{padding:8px 14px;border:1px solid #2b3760;border-radius:10px;background:#141c33;color:var(--text);cursor:pointer}
    .tabbtn.active{box-shadow:0 0 0 2px var(--accent) inset}
    .panel{display:none}
    .panel.active{display:block}
    .row{display:grid;grid-template-columns:80px 140px 1fr 90px 90px 120px 90px;gap:10px;align-items:center;padding:10px;border-bottom:1px solid #1e2740}
    .row.h{color:var(--muted);text-transform:uppercase;font-size:12px}
    .pill{padding:2px 8px;border:1px solid #2a3354;border-radius:999px}
    .inline{display:flex;gap:8px;align-items:center}
    .tight input, .tight select{height:36px}
    .subcard{background:rgba(17,24,42,.6);border:1px solid #222b44;border-radius:12px;padding:12px;margin-top:12px}
    .small{font-size:12px;color:var(--muted)}
    .danger{border-color:var(--danger);color:var(--danger)}
  </style>
</head>
<body>
  <header class="topbar">
    <div class="brand">ByteMe • Admin</div>
    <div class="inline" style="margin-left:auto">
      <input id="admintoken" type="password" placeholder="Admin token" style="width:260px"
             value="">
      <button id="saveToken" class="primary">Save</button>
    </div>
  </header>

  <main class="container">
    <div class="tabs">
      <button class="tabbtn active" data-tab="pending">Pending bookings</button>
      <button class="tabbtn" data-tab="activities">Activities</button>
      <button class="tabbtn" data-tab="edit">Edit activity</button>
      <button class="tabbtn" data-tab="quotas">Quotas</button>
      <button class="tabbtn" data-tab="confirmed">Confirmed</button>
      <button class="tabbtn" data-tab="rejected">Rejected</button>
    </div>

    <!-- PENDING -->
    <section id="panel-pending" class="panel active card">
      <div id="pendingWrap" class="subcard">Loading…</div>
    </section>

    <!-- ACTIVITIES (quick inline edit like you already have) -->
    <section id="panel-activities" class="panel card">
      <div id="actWrap" class="subcard">Loading…</div>
    </section>

    <!-- EDIT ACTIVITY (full CRUD + slots) -->
    <section id="panel-edit" class="panel card">
      <div class="subcard">
        <div class="inline tight" style="flex-wrap:wrap">
          <select id="editPick" style="min-width:260px"></select>
          <button id="btnNewAct" class="primary">New activity</button>
          <button id="btnDeleteAct" class="danger">Delete activity</button>
        </div>

        <div class="subcard">
          <div class="form-grid">
            <div>
              <label>RI</label>
              <select id="e_ri"></select>
            </div>
            <div>
              <label>Cycles (comma-separated, e.g. C2,C3)</label>
              <input type="text" id="e_cycle" placeholder="C3,C4">
            </div>
            <div>
              <label>Name</label>
              <input type="text" id="e_name" placeholder="Workshop title">
            </div>
            <div>
              <label>Duration (hours)</label>
              <input type="number" step="0.25" id="e_dur" value="1">
            </div>
            <div>
              <label>Group size</label>
              <input type="number" id="e_group" value="25">
            </div>
            <div>
              <label>Published</label>
              <select id="e_pub"><option value="1">Yes</option><option value="0">No</option></select>
            </div>
            <div style="grid-column:1 / -1">
              <label>Summary / Description</label>
              <input type="text" id="e_summary" placeholder="Short description">
            </div>
          </div>
          <div class="actions">
            <button id="btnSaveAct" class="primary">Save activity</button>
          </div>
        </div>

        <div class="subcard">
          <div class="inline"><strong>Slots</strong>
            <span class="small">UTC timestamps &nbsp;YYYY-MM-DD HH:MM:SS</span>
          </div>
          <div id="slotsWrap" class="subcard">Pick an activity…</div>

          <div class="inline tight" style="flex-wrap:wrap;margin-top:8px">
            <input id="slot_start" type="text" placeholder="2025-11-14 10:00:00">
            <input id="slot_end"   type="text" placeholder="2025-11-14 11:30:00">
            <button id="slotAdd" class="primary">Add slot</button>
          </div>
        </div>
      </div>
    </section>

    <!-- QUOTAS -->
    <section id="panel-quotas" class="panel card">
      <div id="quotasWrap" class="subcard">Loading…</div>
    </section>

    <!-- CONFIRMED -->
    <section id="panel-confirmed" class="panel card">
      <div id="confWrap" class="subcard">Loading…</div>
    </section>

    <!-- REJECTED -->
    <section id="panel-rejected" class="panel card">
      <div id="rejWrap" class="subcard">Loading…</div>
    </section>
  </main>

  <script>
  const BASE = (location.pathname.endsWith('/') ? location.pathname : location.pathname.replace(/admin\.php$/, ''));
  const tokIn = document.getElementById('admintoken');
  const hdr  = () => ({ 'Authorization': 'Bearer ' + (localStorage.getItem('bm_admin')||'') , 'Content-Type':'application/json' });

  document.getElementById('saveToken').onclick = () => {
    localStorage.setItem('bm_admin', tokIn.value);
    alert('Saved. Reloading.');
    location.reload();
  };
  tokIn.value = localStorage.getItem('bm_admin') || '';

  // Tabs
  document.querySelectorAll('.tabbtn').forEach(b=>{
    b.onclick = () => {
      document.querySelectorAll('.tabbtn').forEach(x=>x.classList.remove('active'));
      document.querySelectorAll('.panel').forEach(x=>x.classList.remove('active'));
      b.classList.add('active');
      document.getElementById('panel-'+b.dataset.tab).classList.add('active');
      // lazy load
      if (b.dataset.tab==='pending') loadPending();
      if (b.dataset.tab==='activities') loadActivities();
      if (b.dataset.tab==='edit') loadEditBoot();
      if (b.dataset.tab==='quotas') loadQuotas();
      if (b.dataset.tab==='confirmed') loadBookings('CONFIRMED','#confWrap');
      if (b.dataset.tab==='rejected')  loadBookings('REJECTED','#rejWrap');
    };
  });

  // helpers
  const j = (sel, root=document) => root.querySelector(sel);
  const jj= (sel, root=document) => root.querySelectorAll(sel);
  const esc = s => String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));
  async function get(path){ const r=await fetch(`${BASE}?api=${path}`, {headers: hdr()}); if(!r.ok) throw new Error(await r.text()); return r.json(); }
  async function post(path, body){ const r=await fetch(`${BASE}?api=${path}`, {method:'POST', headers: hdr(), body: JSON.stringify(body)}); if(!r.ok) throw new Error(await r.text()); return r.json(); }

  // Pending
  async function loadPending(){
    const wrap = j('#pendingWrap');
    const data = await get('admin_bookings&status=PENDING');
    if (!data.length){ wrap.innerHTML='No pending bookings.'; return; }
    wrap.innerHTML = data.map(b=>`
      <div class="row" style="grid-template-columns:90px 160px 1fr 160px 160px 120px 220px 200px;">
        <div>#${b.id}</div>
        <div><span class="pill">${esc(b.ri_name)}</span></div>
        <div>${esc(b.activity_name)}</div>
        <div>${esc(b.teacher_name)}</div>
        <div>${esc(b.teacher_email)}</div>
        <div>${esc(b.teacher_cycle)}</div>
        <div>${esc(b.starts_at)} → ${esc(b.ends_at)}</div>
        <div class="inline">
          <button onclick="approve(${b.id})" class="primary">Approve</button>
          <button onclick="rejectB(${b.id})">Reject</button>
        </div>
      </div>
    `).join('');
  }
  window.approve = async (id)=>{ await post('approve',{booking_id:id}); alert('Approved'); loadPending(); };
  window.rejectB = async (id)=>{ await post('reject' ,{booking_id:id}); alert('Rejected'); loadPending(); };

  // Activities (quick)
  async function loadActivities(){
    const wrap = j('#actWrap');
    const acts = await get('admin_activities');
    if (!acts.length){ wrap.innerHTML='No activities.'; return; }
    wrap.innerHTML = `
      <div class="row h"><div>ID</div><div>RI</div><div>Cycle(s)</div><div>Name</div><div>Dur (h)</div><div>Group</div><div>Published</div></div>
      ${acts.map(a=>`
        <div class="row">
          <div>#${a.id}</div>
          <div>${esc(a.ri_name)}</div>
          <div>${esc(a.cycle)}</div>
          <div>${esc(a.name)}</div>
          <div>${a.duration_hours}</div>
          <div>${a.group_size}</div>
          <div>${a.is_published?'Yes':'No'}</div>
        </div>`).join('')}
    `;
  }

  // Edit activity + slots
  let currentActId = null;
  async function loadEditBoot(){
    const picker = j('#editPick');
    const riSel  = j('#e_ri');
    const [acts, ris] = await Promise.all([ get('admin_activities'), get('admin_ris') ]);

    picker.innerHTML = acts.map(a=>`<option value="${a.id}">#${a.id} • ${esc(a.name)}</option>`).join('');
    riSel.innerHTML  = ris.map(r=>`<option value="${r.id}">${esc(r.name)}</option>`).join('');

    picker.onchange = ()=>loadEdit(parseInt(picker.value,10));
    if (acts.length){ picker.value = acts[0].id; loadEdit(acts[0].id); }
    j('#btnNewAct').onclick = newActivity;
    j('#btnDeleteAct').onclick = delActivity;
    j('#btnSaveAct').onclick = saveActivity;
    j('#slotAdd').onclick = addSlot;
  }

  async function loadEdit(id){
    currentActId = id;
    const a = (await get('admin_activity_get&id='+id));
    j('#e_ri').value = a.ri_id;
    j('#e_cycle').value = a.cycle;
    j('#e_name').value  = a.name;
    j('#e_dur').value   = a.duration_hours;
    j('#e_group').value = a.group_size;
    j('#e_pub').value   = a.is_published ? '1':'0';
    j('#e_summary').value = a.summary || '';

    const slots = await get('admin_slots&activity_id='+id);
    renderSlots(slots);
  }

  function renderSlots(slots){
    const w = j('#slotsWrap');
    if (!slots.length){ w.innerHTML='No slots.'; return; }
    w.innerHTML = `
      <div class="row h" style="grid-template-columns:90px 220px 220px 120px 140px">
        <div>ID</div><div>Starts</div><div>Ends</div><div>Status</div><div></div>
      </div>
      ${slots.map(s=>`
        <div class="row" style="grid-template-columns:90px 220px 220px 120px 140px">
          <div>#${s.id}</div>
          <div><input value="${esc(s.starts_at)}" id="st_${s.id}"></div>
          <div><input value="${esc(s.ends_at)}"   id="en_${s.id}"></div>
          <div>${esc(s.status)}</div>
          <div class="inline">
            <button onclick="saveSlot(${s.id})" class="primary">Save</button>
            <button onclick="delSlot(${s.id})">Delete</button>
          </div>
        </div>`).join('')}
    `;
  }

  async function newActivity(){
    const a = await post('admin_activity_save', {
      ri_id:  (j('#e_ri').value|0),
      cycle:  j('#e_cycle').value.trim(),
      name:   j('#e_name').value.trim() || 'New activity',
      duration_hours: parseFloat(j('#e_dur').value||'1'),
      group_size:     parseInt(j('#e_group').value||'25',10),
      is_published:   parseInt(j('#e_pub').value,10),
      summary: j('#e_summary').value.trim()
    });
    alert('Created #'+a.id);
    loadEditBoot();
  }
  async function saveActivity(){
    if (!currentActId) return;
    await post('admin_activity_save', {
      id: currentActId,
      ri_id:(j('#e_ri').value|0),
      cycle:j('#e_cycle').value.trim(),
      name:j('#e_name').value.trim(),
      duration_hours: parseFloat(j('#e_dur').value||'1'),
      group_size: parseInt(j('#e_group').value||'25',10),
      is_published: parseInt(j('#e_pub').value,10),
      summary:j('#e_summary').value.trim()
    });
    alert('Saved');
  }
  async function delActivity(){
    if (!currentActId) return;
    if (!confirm('Delete activity #'+currentActId+' ?')) return;
    await post('admin_activity_delete', {id: currentActId});
    alert('Deleted');
    loadEditBoot();
  }
  async function addSlot(){
    if (!currentActId) return;
    const starts = j('#slot_start').value.trim();
    const ends   = j('#slot_end').value.trim();
    await post('admin_slot_save', { activity_id: currentActId, starts_at: starts, ends_at: ends });
    const slots = await get('admin_slots&activity_id='+currentActId);
    renderSlots(slots);
  }
  window.saveSlot = async (id)=>{
    const st=j('#st_'+id).value.trim(), en=j('#en_'+id).value.trim();
    await post('admin_slot_save',{ id, starts_at:st, ends_at:en });
    alert('Slot saved');
  };
  window.delSlot = async (id)=>{
    if(!confirm('Delete slot #'+id+' ?')) return;
    await post('admin_slot_delete',{ id });
    const slots = await get('admin_slots&activity_id='+currentActId);
    renderSlots(slots);
  };

  // Quotas
  async function loadQuotas(){
    const wrap = j('#quotasWrap');
    const data = await get('admin_quotas');
    wrap.innerHTML = `
      <div class="row h" style="grid-template-columns:160px 120px 120px 120px 120px 120px 100px">
        <div>RI</div><div>Trim quota</div><div>Trim used</div><div>Trim left</div>
        <div>Year quota</div><div>Year used</div><div></div>
      </div>
      ${data.map(r=>`
        <div class="row" style="grid-template-columns:160px 120px 120px 120px 120px 120px 100px">
          <div>${esc(r.name)}</div>
          <div><input type="number" id="qt_${r.id}_t" value="${r.quota_hours_trimester}"></div>
          <div>${r.consumed_trimester}</div>
          <div>${r.remaining_trimester}</div>
          <div><input type="number" id="qt_${r.id}_y" value="${r.quota_hours_year}"></div>
          <div>${r.consumed_year}</div>
          <div><button onclick="saveQuota(${r.id})" class="primary">Save</button></div>
        </div>
      `).join('')}
    `;
  }
  window.saveQuota = async (id)=>{
    const t = parseFloat(j('#qt_'+id+'_t').value||'0');
    const y = parseFloat(j('#qt_'+id+'_y').value||'0');
    await post('admin_quotas_update',{ ri_id:id, quota_hours_trimester:t, quota_hours_year:y });
    alert('Saved.');
    loadQuotas();
  };

  // Confirmed/Rejected
  async function loadBookings(status, selector){
    const wrap = j(selector);
    const data = await get('admin_bookings&status='+encodeURIComponent(status));
    if (!data.length){ wrap.innerHTML='None.'; return; }
    wrap.innerHTML = data.map(b=>`
      <div class="row" style="grid-template-columns:90px 160px 1fr 160px 160px 120px 220px;">
        <div>#${b.id}</div>
        <div><span class="pill">${esc(b.ri_name)}</span></div>
        <div>${esc(b.activity_name)}</div>
        <div>${esc(b.teacher_name)}</div>
        <div>${esc(b.teacher_email)}</div>
        <div>${esc(b.teacher_cycle)}</div>
        <div>${esc(b.starts_at)} → ${esc(b.ends_at)}</div>
      </div>
    `).join('');
  }

  // boot first tab
  loadPending();
  </script>
</body>
</html>

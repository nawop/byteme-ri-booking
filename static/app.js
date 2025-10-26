console.log("ByteMe JS loaded");

const BASE = (location.pathname.endsWith('/') ? location.pathname : location.pathname + '/');

async function api(endpoint, opts = {}) {
  const url = `${BASE}?api=${endpoint}`;
  const res = await fetch(url, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    ...opts
  });
  const raw = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${raw}`);
  try { return JSON.parse(raw); }
  catch (e) { throw new Error(`Bad JSON: ${e.message}. Raw: ${raw.slice(0,200)}`); }
}

// Parse stored local times as local (no Z), show in user’s locale
function fmtDateTime(iso){
  const d = new Date(iso.replace(' ', 'T'));
  return d.toLocaleString([], {dateStyle:'medium', timeStyle:'short'});
}

function esc(s){
  return String(s ?? '').replace(/[&<>"']/g, m => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
  ));
}

function rowTemplate(a){
  const sum = encodeURIComponent(a.summary ?? '');
  const ri  = encodeURIComponent(a.ri_name ?? '');

  // support multiple cycles like "C2,C3" -> colored badges in a .cycles wrapper
  const cycles = (a.cycle || a.cycles || '')
    .split(',')
    .map(c => c.trim())
    .filter(Boolean);

  const cyclesHtml = cycles.length
    ? `<div class="cycles">${cycles.map(c => `<span class="badge ${esc(c)}">${esc(c)}</span>`).join('')}</div>`
    : `<div class="cycles"><span class="badge ${esc(a.cycle||'')}">${esc(a.cycle||'')}</span></div>`;

  return `
  <div class="activity-row" data-id="${a.id}" data-summary="${sum}" data-ri="${ri}">
    <div class="v">${cyclesHtml}</div>
    <div class="v">${esc(a.name)}</div>
    <div class="v">${a.duration_hours} h</div>
    <div class="v">${a.group_size}</div>
    <div class="v">${a.slots_available} fräi</div>
    <div class="toggle" aria-label="toggle" role="button" tabindex="0"></div>
    <div class="details"></div>
  </div>`;
}

async function renderActivities(){
  const wrap=document.getElementById('activities');
  wrap.innerHTML = `<div class="card" style="padding:12px;opacity:.8">Loading…</div>`;
  const data = await api('activities');
  if (!Array.isArray(data) || data.length===0){
    wrap.innerHTML = `<div class="card" style="padding:16px;">No activities published yet.</div>`;
    return;
  }
  wrap.innerHTML = `
    <div class="activity-row h">
      <div>Cycle</div><div>Aktivitéit</div><div>Dauer</div><div>Participant</div><div>Plazen</div><div></div><div></div>
    </div>
    ${data.map(rowTemplate).join('')}
  `;
  wrap.querySelectorAll('.toggle').forEach(tg=>{
    const toggleHandler = async (e)=>{
      const row=e.currentTarget.closest('.activity-row');
      const details=row.querySelector('.details');

      if(details.classList.contains('open')){
        details.classList.remove('open');
        row.classList.remove('open');
        details.innerHTML='';
        return;
      }

      row.classList.add('open');
      const id=row.dataset.id;
      const slots=await api(`slots&activity_id=${id}`);
      const summary = decodeURIComponent(row.dataset.summary || '');
      const riName  = decodeURIComponent(row.dataset.ri || '');
      details.classList.add('open');
      details.innerHTML=detailTemplate(slots, summary, riName);
      wireDetail(details);
    };

    tg.addEventListener('click', toggleHandler);
    tg.addEventListener('keydown', (ev)=>{
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); tg.click(); }
    });
  });
}

function detailTemplate(slots, summaryText, riName){
  const opts = slots.map(s=>{
    const disabled=(s.status!=='OPEN')?'disabled':'';
    const pendingClass=(s.status==='PENDING')?'pending':'';
    return `<label class="dateopt ${pendingClass}">
      <input type="radio" name="datepick" value="${s.id}" ${disabled}/>
      ${fmtDateTime(s.starts_at)}
    </label>`;
  }).join('');

  const descBlock = summaryText
    ? `<div class="summary" style="margin-bottom:6px; color:var(--text); font-weight:500;">${esc(summaryText)}</div>`
    : `<div class="summary" style="margin-bottom:6px; color:var(--muted); font-style:italic;">Description coming soon.</div>`;

  const riBlock = riName
    ? `<div class="summary" style="margin:0 0 4px 0; color:var(--muted); font-size:13px;">Vum ${esc(riName)}</div>`
    : '';

  return `
    ${riBlock}
    ${descBlock}
    <div class="hr"></div>

    <div class="summary">Wielt en Datum a fëllt de Formulaire aus, fir eng Reservatioun unzefroen. Déi gro Datumer sin am Gaang bestätegt ze ginn.</div>
    <div class="dates">${opts || '<em>De Moment si keng Datumer fräi.</em>'}</div>

    <div class="hr"></div>
    <div class="form-grid">
      <div><label>Numm vum Enseigant</label><input type="text" id="tname" placeholder="Bernd das Brot"/></div>
      <div><label>Cycle</label><select id="tcycle"><option value="">Wielen…</option><option>C1</option><option>C2</option><option>C3</option><option>C4</option></select></div>
      <div><label>Email</label><input type="email" id="temail" placeholder="bernd@education.lu"/></div>
    </div>
    <div class="actions">
      <button id="book" class="primary" disabled>Buchung ofschécken</button>
    </div>
  `;
}

function wireDetail(details){
  const radios=details.querySelectorAll('input[name="datepick"]');
  const bookBtn=details.querySelector('#book');
  let chosen=null;

  function validate(){
    const name=details.querySelector('#tname').value.trim();
    const email=details.querySelector('#temail').value.trim();
    const cycle=details.querySelector('#tcycle').value.trim();
    bookBtn.disabled=!(chosen && name && email && cycle && /\S+@\S+\.\S+/.test(email));
  }

  radios.forEach(r=>r.addEventListener('change',(e)=>{ chosen=parseInt(e.target.value,10); validate(); }));
  details.querySelectorAll('input,select').forEach(el=>el.addEventListener('input', validate));

  bookBtn.addEventListener('click', async ()=>{
    const name=details.querySelector('#tname').value.trim();
    const email=details.querySelector('#temail').value.trim();
    const cycle=details.querySelector('#tcycle').value.trim();
    bookBtn.disabled=true;
    try{
      await api('book', { method:'POST', body:JSON.stringify({slot_id:chosen, teacher_name:name, teacher_email:email, teacher_cycle:cycle}) });
      alert('Request sent! Your booking is pending approval.');
    }catch(e){ alert('Booking failed: '+e);} finally{ bookBtn.disabled=false; }
  });
}

renderActivities();

<?php
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
require_once __DIR__ . '/../../app/helpers/attendance.php';
$u = require_login(['super_admin', 'admin', 'guru', 'ketua_kelas']);
$pdo = db();
$allowed = allowed_class_ids($u);
$all = $pdo->query('SELECT id, name FROM classes WHERE is_active=1 ORDER BY name')->fetchAll();
$classes = $allowed === null ? $all : array_values(array_filter($all, fn($c) => in_array((int)$c['id'], $allowed, true)));
$classId = (int)($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
if (!in_array($classId, array_column($classes, 'id'), false)) $classId = (int)($classes[0]['id'] ?? 0);
$sessions = $classId ? sessions_for_class($pdo, $classId) : [];
render_header('Scan Absensi', 'scanner'); ?>
<?php if (!$classes): ?><div class="alert alert-warning">Anda belum ditugaskan pada kelas mana pun. Hubungi admin.</div><?php else: ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card stat-card"><div class="card-body">
      <form method="get" class="row g-2 mb-3">
        <div class="col-6"><label class="form-label small">Kelas</label>
          <select name="class_id" class="form-select" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $classId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small">Sesi</label>
          <select id="session" class="form-select"><?php foreach ($sessions as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?> (<?= substr($s['start_time'], 0, 5) ?>-<?= substr($s['end_time'], 0, 5) ?>)<?= session_is_open($s) ? '' : ' - tutup' ?></option><?php endforeach; ?></select></div>
      </form>
      <?php if (!$sessions): ?><div class="alert alert-warning py-2">Tidak ada sesi aktif untuk kelas ini hari ini.</div><?php endif; ?>
      <div class="d-flex gap-2 mb-2 flex-wrap">
        <select id="camera" class="form-select form-select-sm w-auto flex-grow-1"><option value="">Kamera belakang (default)</option></select>
        <button class="btn btn-primary btn-sm" id="btnStart"><i class="bi bi-camera-video"></i> Mulai Scan</button>
        <button class="btn btn-outline-danger btn-sm" id="btnStop" disabled><i class="bi bi-stop-circle"></i> Hentikan</button>
      </div>
      <div class="d-flex align-items-center gap-2 small mb-2"><span id="dot" class="rounded-circle d-inline-block" style="width:10px;height:10px;background:#9ca3af"></span><span id="camState" class="text-muted">Kamera nonaktif</span></div>
      <div id="reader" class="rounded-4 overflow-hidden bg-dark" style="min-height:260px"></div>
      <div class="small text-muted mt-2">Kamera memerlukan HTTPS (atau localhost).</div>
    </div></div>
    <div id="result" class="card stat-card mt-3 d-none"><div class="card-body d-flex gap-3 align-items-center" id="resultBody"></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card stat-card"><div class="card-body">
      <div class="d-flex justify-content-between"><h6 class="mb-2">Kehadiran Hari Ini</h6><span class="badge text-bg-secondary" id="cnt"></span></div>
      <ul class="nav nav-pills mb-2"><li class="nav-item"><button class="nav-link active py-1" data-bs-toggle="pill" data-bs-target="#tAbsent">Belum hadir</button></li><li class="nav-item"><button class="nav-link py-1" data-bs-toggle="pill" data-bs-target="#tPresent">Sudah hadir</button></li></ul>
      <div class="tab-content" style="max-height:420px;overflow:auto"><div class="tab-pane fade show active" id="tAbsent"></div><div class="tab-pane fade" id="tPresent"></div></div>
    </div></div>
  </div>
</div>
<?php endif; render_footer(); ?>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const CSRF=<?= json_encode(csrf_token()) ?>, CLASS_ID=<?= (int)$classId ?>, SCAN_URL=<?= json_encode(url('modules/scanner/scan.php')) ?>, STATUS_URL=<?= json_encode(url('modules/scanner/status.php')) ?>;
const q=s=>document.querySelector(s); let scanner=null, busy=false, lastToken='', lastAt=0;
const colors={success:'#16a34a',warning:'#d97706',danger:'#dc2626'}, icons={success:'bi-check-circle-fill',warning:'bi-exclamation-triangle-fill',danger:'bi-x-circle-fill'};
function esc(t){const d=document.createElement('div');d.textContent=t==null?'':t;return d.innerHTML}
function beep(level){try{const c=new (window.AudioContext||window.webkitAudioContext)(),o=c.createOscillator(),g=c.createGain();o.connect(g);g.connect(c.destination);o.frequency.value=level==='success'?880:level==='warning'?520:220;g.gain.value=.15;o.start();o.stop(c.currentTime+(level==='success'?.15:.35))}catch(e){}}
function show(r){
  const lv=r.level, s=r.student, c=colors[lv];
  q('#result').classList.remove('d-none');
  q('#result').style.borderLeft='6px solid '+c;
  q('#resultBody').innerHTML=(s?(s.photo?`<img src="${esc(s.photo)}" width="72" height="72" class="rounded-3 object-fit-cover">`:`<div class="stat-icon" style="width:72px;height:72px;font-size:1.8rem">${esc(s.name.charAt(0))}</div>`):`<i class="bi ${icons[lv]}" style="font-size:3rem;color:${c}"></i>`)+
   `<div><div class="fw-bold" style="color:${c}"><i class="bi ${icons[lv]}"></i> ${esc(r.message)}</div>`+(s?`<div class="fw-semibold">${esc(s.name)}</div><div class="small text-muted">NIS ${esc(s.nis)} &middot; ${esc(s.dept)} &middot; ${esc(s.class)}</div>`:'')+(r.time?`<div class="small">Waktu: ${esc(r.time)}${r.status?` &middot; <span class="badge" style="background:${c}">${esc(r.status)}</span>`:''}</div>`:'')+`</div>`;
  beep(lv); if(navigator.vibrate) navigator.vibrate(lv==='success'?120:[80,60,80]);
}
async function send(token){
  const now=Date.now(); if(busy||(token===lastToken&&now-lastAt<3000))return; busy=true;lastToken=token;lastAt=now;
  try{
    const fd=new FormData();fd.append('token',token);fd.append('session_id',q('#session')?.value||'');fd.append('class_id',CLASS_ID);
    const res=await fetch(SCAN_URL,{method:'POST',body:fd,headers:{'X-CSRF-Token':CSRF}});
    show(await res.json()); loadStatus();
  }catch(e){show({level:'danger',message:'Gagal terhubung ke server.'})}
  finally{setTimeout(()=>busy=false,900)}
}
async function loadStatus(){
  const sid=q('#session')?.value; if(!sid)return;
  const r=await (await fetch(`${STATUS_URL}?class_id=${CLASS_ID}&session_id=${sid}`)).json();
  q('#tAbsent').innerHTML=(r.absent||[]).map(x=>`<div class="d-flex justify-content-between border-bottom py-1 small"><span>${esc(x.name)}</span><span class="text-muted">${esc(x.nis)}</span></div>`).join('')||'<div class="text-muted small p-2">Semua siswa sudah hadir.</div>';
  q('#tPresent').innerHTML=(r.present||[]).map(x=>`<div class="d-flex justify-content-between border-bottom py-1 small"><span>${esc(x.name)}</span><span><span class="badge text-bg-${x.status==='HADIR'?'success':x.status==='TERLAMBAT'?'warning':'info'}">${esc(x.status)}</span> ${esc(x.time)}</span></div>`).join('')||'<div class="text-muted small p-2">Belum ada.</div>';
  q('#cnt').textContent=(r.present||[]).length+' hadir / '+(r.absent||[]).length+' belum';
}
function setState(on){q('#dot').style.background=on?'#22c55e':'#9ca3af';q('#camState').textContent=on?'Kamera aktif':'Kamera nonaktif';q('#btnStart').disabled=on;q('#btnStop').disabled=!on}
q('#btnStart')?.addEventListener('click',async()=>{
  try{
    scanner=scanner||new Html5Qrcode('reader');
    const cam=q('#camera').value||{facingMode:'environment'};
    await scanner.start(cam,{fps:10,qrbox:{width:240,height:240}},t=>send(t),()=>{});
    setState(true);
  }catch(e){Swal.fire({icon:'error',title:'Kamera tidak dapat dibuka',text:String(e)})}
});
q('#btnStop')?.addEventListener('click',async()=>{try{await scanner.stop()}catch(e){} setState(false)});
q('#session')?.addEventListener('change',loadStatus);
Html5Qrcode.getCameras().then(cs=>cs.forEach(c=>{const o=document.createElement('option');o.value=c.id;o.textContent=c.label||c.id;q('#camera').appendChild(o)})).catch(()=>{});
loadStatus(); setInterval(loadStatus,10000);
</script>

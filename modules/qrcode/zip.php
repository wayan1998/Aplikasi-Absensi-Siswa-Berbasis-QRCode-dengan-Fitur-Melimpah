<?php
// Unduh QR siswa sebagai ZIP (PNG per siswa + daftar.csv). Gambar dibuat di browser dengan library QR yang
// sama dengan kartu cetak, sehingga server tidak memerlukan library tambahan.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
require_once __DIR__ . '/../../app/helpers/qr.php';
$u = require_login(['super_admin', 'admin', 'guru']);
$pdo = db();
$allowed = allowed_class_ids($u);   // null = semua kelas

$classes = $pdo->query('SELECT id, name FROM classes WHERE is_active=1 ORDER BY name')->fetchAll();
if ($allowed !== null) $classes = array_values(array_filter($classes, fn($c) => in_array((int)$c['id'], $allowed, true)));
$classIds = array_map(fn($c) => (int)$c['id'], $classes);

// Catat unduhan (dipanggil browser setelah ZIP selesai dibuat)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    log_activity('download_qr_zip', 'classes', (int)($_POST['class_id'] ?? 0), (int)($_POST['count'] ?? 0) . ' QR');
    header('Content-Type: application/json'); echo '{"ok":true}'; exit;
}

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId && !in_array($classId, $classIds, true)) render_error_page(403);
$scope = $classId ? [$classId] : $classIds;   // 0 = semua kelas yang boleh diakses

$students = [];
if ($scope) {
    $in = implode(',', array_fill(0, count($scope), '?'));
    $st = $pdo->prepare("SELECT s.id, s.nis, s.full_name, c.name AS class_name FROM students s JOIN classes c ON c.id=s.class_id
        WHERE s.status='aktif' AND s.class_id IN ($in) ORDER BY c.name, s.full_name");
    $st->execute($scope);
    $tok = $pdo->prepare('SELECT token FROM student_qr_codes WHERE student_id=? AND is_active=1 ORDER BY id DESC LIMIT 1');
    foreach ($st->fetchAll() as $s) {
        $tok->execute([$s['id']]);
        $token = $tok->fetchColumn() ?: create_qr_for_student($pdo, (int)$s['id'], $u['id']);
        $students[] = ['nis' => $s['nis'], 'name' => $s['full_name'], 'class' => $s['class_name'], 'token' => $token];
    }
}
header('Cache-Control: no-store');
$scopeName = $classId ? ($classes[array_search($classId, $classIds, true)]['name'] ?? 'kelas') : 'SEMUA_KELAS';
render_header('Unduh QR (ZIP)', 'students'); ?>
<div class="card stat-card"><div class="card-body">
  <form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-4"><label class="form-label small">Kelas</label>
      <select name="class_id" class="form-select" onchange="this.form.submit()">
        <option value="0">Semua kelas<?= $allowed !== null ? ' yang saya ampu' : '' ?></option>
        <?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $classId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-8 text-md-end"><span class="text-muted small me-2"><?= count($students) ?> siswa aktif</span>
      <button type="button" id="go" class="btn btn-primary" <?= $students ? '' : 'disabled' ?>><i class="bi bi-file-earmark-zip"></i> Buat & Unduh ZIP</button></div>
  </form>
  <div class="progress mb-2 d-none" id="pgWrap" style="height:20px"><div class="progress-bar" id="pg" style="width:0%">0%</div></div>
  <div class="small text-muted" id="st"></div>
  <div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-shield-exclamation"></i> File ini berisi QR yang dapat dipakai untuk absen atas nama siswa. Simpan di tempat aman dan jangan dibagikan ke siswa lain atau grup umum. Gambar dibuat di browser Anda (tidak dikirim ke server lain).</div>
</div></div>
<div id="qrbox" style="position:absolute;left:-9999px;top:0"></div>
<?php render_footer(); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const DATA=<?= json_encode($students, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const SCOPE=<?= json_encode(preg_replace('/[^\w.-]+/', '_', $scopeName)) ?>, CLASS_ID=<?= (int)$classId ?>, CSRF=<?= json_encode(csrf_token()) ?>;
const safe=s=>String(s).replace(/[^\w.-]+/g,'_').replace(/^_+|_+$/g,'')||'x';
const tick=()=>new Promise(r=>setTimeout(r,0));
function qrCanvas(token){
  return new Promise(res=>{
    const box=document.getElementById('qrbox');box.innerHTML='';
    new QRCode(box,{text:token,width:300,height:300,correctLevel:QRCode.CorrectLevel.M});
    const src=box.querySelector('canvas');
    const c=document.createElement('canvas');c.width=c.height=360;const x=c.getContext('2d');
    x.fillStyle='#fff';x.fillRect(0,0,360,360);x.drawImage(src,30,30,300,300);   // zona tenang putih agar mudah dipindai
    c.toBlob(res,'image/png');
  });
}
document.getElementById('go').addEventListener('click',async function(){
  const btn=this;btn.disabled=true;
  const wrap=document.getElementById('pgWrap'),pg=document.getElementById('pg'),st=document.getElementById('st');wrap.classList.remove('d-none');
  try{
    const zip=new JSZip(),multi=CLASS_ID===0,used=new Set();
    const csv=['NIS,Nama,Kelas,File'];
    for(let i=0;i<DATA.length;i++){
      const s=DATA[i];
      let path=(multi?safe(s.class)+'/':'')+safe(s.nis)+'_'+safe(s.name)+'.png';
      while(used.has(path))path=path.replace(/\.png$/,'_'+i+'.png');used.add(path);
      zip.file(path,await qrCanvas(s.token));
      csv.push([s.nis,s.name,s.class,path].map(v=>'"'+String(v).replace(/"/g,'""')+'"').join(','));
      const p=Math.round((i+1)/DATA.length*90);pg.style.width=p+'%';pg.textContent=p+'%';st.textContent='Membuat QR '+(i+1)+' / '+DATA.length;
      if(i%10===0)await tick();
    }
    zip.file('daftar.csv','﻿'+csv.join('\r\n'));
    const blob=await zip.generateAsync({type:'blob',compression:'DEFLATE'},m=>{const p=90+Math.round(m.percent/10);pg.style.width=p+'%';pg.textContent=p+'%'});
    const a=document.createElement('a');a.href=URL.createObjectURL(blob);
    a.download='QR_'+SCOPE+'_'+new Date().toISOString().slice(0,10)+'.zip';document.body.appendChild(a);a.click();a.remove();
    st.textContent='Selesai: '+DATA.length+' QR diunduh.';
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('class_id',CLASS_ID);fd.append('count',DATA.length);
    fetch(location.href,{method:'POST',body:fd}).catch(()=>{});
  }catch(err){st.textContent='Gagal membuat ZIP: '+err.message;}
  finally{btn.disabled=false;}
});
</script>

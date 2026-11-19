<?php
// Cetak kartu absensi digital: 6 kartu per lembar kertas F4 (215 x 330 mm), 2 kolom x 3 baris.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/qr.php';
$u = require_login(['super_admin', 'admin', 'guru']);
$pdo = db();
$allowed = allowed_class_ids($u);   // null = semua kelas

$classId = (int)($_GET['class_id'] ?? 0); $studentId = (int)($_GET['student_id'] ?? 0);
$classes = $pdo->query('SELECT id, name FROM classes WHERE is_active=1 ORDER BY name')->fetchAll();
if ($allowed !== null) $classes = array_values(array_filter($classes, fn($c) => in_array((int)$c['id'], $allowed, true)));

$students = [];
if ($studentId || $classId) {
    $sql = "SELECT s.*, c.name AS class_name, d.name AS dept_name, d.code AS dept_code
            FROM students s JOIN classes c ON c.id=s.class_id JOIN departments d ON d.id=c.department_id
            WHERE s.status='aktif' AND " . ($studentId ? 's.id=?' : 's.class_id=?') . ' ORDER BY s.full_name';
    $st = $pdo->prepare($sql); $st->execute([$studentId ?: $classId]); $students = $st->fetchAll();
    if ($allowed !== null) $students = array_values(array_filter($students, fn($s) => in_array((int)$s['class_id'], $allowed, true)));
    foreach ($students as &$s) {  // pastikan setiap siswa punya token aktif
        $t = $pdo->prepare('SELECT token FROM student_qr_codes WHERE student_id=? AND is_active=1 ORDER BY id DESC LIMIT 1');
        $t->execute([$s['id']]); $s['token'] = $t->fetchColumn() ?: create_qr_for_student($pdo, (int)$s['id'], $u['id']);
    }
    unset($s);
}
$school = setting('school_name', 'SMK NEGERI 1 MOPUYA');
$logo = setting('logo');
$sheets = array_chunk($students, 6);
?><!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kartu Absensi Digital</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{background:#e9edf5;font-family:'Segoe UI',Arial,sans-serif}
.sheet{width:215mm;height:330mm;background:#fff;margin:10px auto;padding:8mm;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:repeat(3,1fr);box-shadow:0 2px 12px rgba(0,0,0,.15);page-break-after:always;break-after:page}
.cell{display:flex;align-items:center;justify-content:center;padding:2.5mm}
.card-id{position:relative;width:100%;height:100%;border:.3mm dashed #8a94a8;border-radius:4mm;overflow:hidden;display:flex;flex-direction:column;background:#fff}
.c-head{background:linear-gradient(135deg,#0b1f4d,#1f4fd8);color:#fff;padding:2.5mm 3mm;display:flex;align-items:center;gap:2.5mm;height:17mm}
.c-head img,.c-logo{width:11mm;height:11mm;object-fit:contain;background:#fff;border-radius:50%;padding:.5mm;flex:none}
.c-logo{display:flex;align-items:center;justify-content:center;color:#1f4fd8;font-size:6mm}
.c-school{font-size:3.1mm;font-weight:700;line-height:1.15}
.c-title{font-size:2.5mm;letter-spacing:.35mm;color:#67e8f9;font-weight:600}
.c-info{display:flex;gap:3mm;padding:3mm;height:30mm}
.c-photo{width:21mm;height:27mm;border-radius:2mm;object-fit:cover;background:#e5e9f2;flex:none;display:flex;align-items:center;justify-content:center;font-size:9mm;color:#8a94a8;font-weight:700}
.c-data{font-size:2.9mm;line-height:1.35;min-width:0}
.c-name{font-size:3.5mm;font-weight:700;color:#0b1f4d;line-height:1.2;margin-bottom:1mm;word-break:break-word}
.c-data div span{display:inline-block;width:12mm;color:#6b7690}
.c-qr{flex:1;display:flex;align-items:center;justify-content:center}
.c-qr img,.c-qr canvas{width:36mm!important;height:36mm!important}
.c-foot{background:#0b1f4d;color:#fff;font-size:2.4mm;text-align:center;padding:1.2mm;letter-spacing:.3mm}
.toolbar{position:sticky;top:0;z-index:5;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.1)}
@page{size:215mm 330mm;margin:0}
@media print{body{background:#fff}.toolbar{display:none!important}.sheet{margin:0;box-shadow:none}.noprint{display:none!important}}
</style></head><body>
<div class="toolbar p-2 d-flex flex-wrap gap-2 align-items-center">
  <form method="get" class="d-flex gap-2 align-items-center">
    <select name="class_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="0">- Pilih kelas -</option>
      <?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $classId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if ($students): ?>
    <span class="small text-muted"><?= count($students) ?> kartu &middot; <?= count($sheets) ?> lembar F4</span>
    <a class="btn btn-sm btn-outline-primary ms-auto" href="<?= e(url('modules/qrcode/zip.php?class_id=' . $classId)) ?>" title="Unduh semua QR sebagai ZIP"><i class="bi bi-file-earmark-zip"></i> Unduh ZIP</a>
    <button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
  <?php endif; ?>
</div>
<?php if (!$students): ?>
  <div class="container py-5 text-center text-muted">Pilih kelas untuk menampilkan kartu.<br><small>Di dialog cetak: ukuran kertas F4 (215 x 330 mm), margin None, skala 100%, aktifkan "Background graphics".</small></div>
<?php endif; ?>
<?php foreach ($sheets as $sheet): ?>
<div class="sheet">
  <?php foreach ($sheet as $s): ?>
  <div class="cell"><div class="card-id">
    <div class="c-head">
      <?php if ($logo): ?><img src="<?= url('uploads/school/' . e($logo)) ?>" alt=""><?php else: ?><div class="c-logo"><i class="bi bi-mortarboard-fill"></i></div><?php endif; ?>
      <div><div class="c-school"><?= e(mb_strtoupper($school)) ?></div><div class="c-title">KARTU ABSENSI DIGITAL</div></div>
    </div>
    <div class="c-info">
      <?php if ($s['photo']): ?><img class="c-photo" src="<?= url('uploads/students/' . e($s['photo'])) ?>" alt=""><?php else: ?><div class="c-photo"><?= e(mb_strtoupper(mb_substr($s['full_name'], 0, 1))) ?></div><?php endif; ?>
      <div class="c-data">
        <div class="c-name"><?= e($s['full_name']) ?></div>
        <div><span>NIS</span><?= e($s['nis']) ?></div>
        <div><span>NISN</span><?= e($s['nisn']) ?></div>
        <div><span>Jurusan</span><?= e($s['dept_code']) ?></div>
        <div><span>Kelas</span><?= e($s['class_name']) ?></div>
      </div>
    </div>
    <div class="c-qr"><div class="qr" data-token="<?= e($s['token']) ?>" data-name="<?= e($s['nis'] . '_' . $s['full_name']) ?>"></div></div>
    <div class="c-foot">KARTU ABSENSI DIGITAL &bull; <?= e($s['nis']) ?></div>
    <button class="btn btn-sm btn-outline-secondary noprint dl" style="position:absolute;top:1mm;right:1mm;padding:0 .35rem" title="Unduh PNG"><i class="bi bi-download"></i></button>
  </div></div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.qr').forEach(function(el){
  new QRCode(el,{text:el.dataset.token,width:300,height:300,correctLevel:QRCode.CorrectLevel.M});
});
document.querySelectorAll('.dl').forEach(function(btn){
  btn.addEventListener('click',function(){
    var el=btn.closest('.card-id').querySelector('.qr'),src=el.querySelector('canvas');
    var c=document.createElement('canvas');c.width=c.height=360;var x=c.getContext('2d');
    x.fillStyle='#fff';x.fillRect(0,0,360,360);x.drawImage(src,30,30,300,300);
    var a=document.createElement('a');a.download='QR_'+el.dataset.name.replace(/[^\w.-]+/g,'_')+'.png';a.href=c.toDataURL('image/png');a.click();
  });
});
</script>
</body></html>

<?php
require_once __DIR__ . '/../app/middleware/auth.php';
require_once __DIR__ . '/../app/views/layout.php';
require_once __DIR__ . '/../app/helpers/attendance.php';
$u = require_login(['super_admin', 'admin']);
$pdo = db(); $today = date('Y-m-d');
$one = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
$all = class_stats($pdo, array_map('intval', $pdo->query('SELECT id FROM classes WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN)), $today);
$stats = [
  ['Total Siswa', $all['total'], 'bi-people'],
  ['Total Jurusan', $one('SELECT COUNT(*) FROM departments WHERE is_active=1'), 'bi-diagram-3'],
  ['Total Kelas', $one('SELECT COUNT(*) FROM classes WHERE is_active=1'), 'bi-building'],
  ['Total Guru', $one('SELECT COUNT(*) FROM teachers WHERE is_active=1'), 'bi-person-badge'],
  ['Hadir Hari Ini', $all['HADIR'], 'bi-check2-circle'], ['Terlambat', $all['TERLAMBAT'], 'bi-alarm'],
  ['Izin', $all['IZIN'], 'bi-envelope-paper'], ['Sakit', $all['SAKIT'], 'bi-bandaid'],
  ['Alpa', $all['ALPA'], 'bi-x-circle'], ['Persentase Hadir', $all['persen'] . '%', 'bi-graph-up'],
];
$P = "('HADIR','TERLAMBAT')";
// 7 hari terakhir
$d7 = $pdo->prepare("SELECT attendance_date d, COUNT(DISTINCT CASE WHEN status IN $P THEN student_id END) hadir, COUNT(DISTINCT CASE WHEN status IN ('IZIN','SAKIT','DISPENSASI') THEN student_id END) izin, COUNT(DISTINCT CASE WHEN status='ALPA' THEN student_id END) alpa FROM attendance WHERE attendance_date >= ? GROUP BY attendance_date ORDER BY attendance_date");
$d7->execute([date('Y-m-d', strtotime('-6 days'))]); $daily = $d7->fetchAll();
// per kelas & jurusan hari ini
$cs = $pdo->prepare("SELECT c.name, d.code dcode, COUNT(DISTINCT s.id) total, COUNT(DISTINCT CASE WHEN a.status IN $P THEN a.student_id END) present
  FROM classes c JOIN departments d ON d.id=c.department_id JOIN students s ON s.class_id=c.id AND s.status='aktif' LEFT JOIN attendance a ON a.student_id=s.id AND a.attendance_date=? WHERE c.is_active=1 GROUP BY c.id, c.name, d.code");
$cs->execute([$today]); $byClass = $cs->fetchAll();
foreach ($byClass as &$r) $r['pct'] = $r['total'] ? round($r['present'] / $r['total'] * 100, 1) : 0; unset($r);
$byDept = [];
foreach ($byClass as $r) { $byDept[$r['dcode']]['t'] = ($byDept[$r['dcode']]['t'] ?? 0) + $r['total']; $byDept[$r['dcode']]['p'] = ($byDept[$r['dcode']]['p'] ?? 0) + $r['present']; }
usort($byClass, fn($a, $b) => $b['pct'] <=> $a['pct']);
// bulanan (6 bulan)
$m = $pdo->prepare("SELECT DATE_FORMAT(attendance_date,'%Y-%m') ym, ROUND(SUM(status IN $P)/COUNT(*)*100,1) pct FROM attendance WHERE attendance_date >= ? GROUP BY ym ORDER BY ym");
$m->execute([date('Y-m-01', strtotime('-5 months'))]); $monthly = $m->fetchAll();
// aktivitas & belum hadir
$recent = $pdo->query("SELECT s.full_name, c.name cname, a.status, a.scanned_at, us.full_name by_name FROM attendance a JOIN students s ON s.id=a.student_id JOIN classes c ON c.id=a.class_id LEFT JOIN users us ON us.id=a.scanned_by ORDER BY a.id DESC LIMIT 10")->fetchAll();
$abs = $pdo->prepare("SELECT s.full_name, s.nis, c.name cname FROM students s JOIN classes c ON c.id=s.class_id WHERE s.status='aktif' AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.student_id=s.id AND a.attendance_date=?) ORDER BY c.name, s.full_name LIMIT 15");
$abs->execute([$today]); $absent = $abs->fetchAll();
$cls = fn($s) => ['HADIR' => 'success', 'TERLAMBAT' => 'warning', 'ALPA' => 'danger'][$s] ?? 'info';
render_header('Dashboard Admin', 'dashboard'); ?>
<div class="row g-3">
<?php foreach ($stats as [$label, $val, $icon]): ?>
  <div class="col-6 col-lg-3 col-xl"><div class="card stat-card"><div class="card-body d-flex align-items-center gap-3">
    <div class="stat-icon"><i class="bi <?= $icon ?>"></i></div>
    <div><div class="text-muted small"><?= e($label) ?></div><div class="fs-4 fw-bold"><?= e($val) ?></div></div>
  </div></div></div>
<?php endforeach; ?>
</div>
<div class="row g-3 mt-1">
  <div class="col-lg-8"><div class="card stat-card"><div class="card-body"><h6>Absensi 7 Hari Terakhir</h6><canvas id="cDaily" height="110"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card stat-card"><div class="card-body"><h6>Kehadiran per Jurusan (hari ini)</h6><canvas id="cDept" height="220"></canvas></div></div></div>
  <div class="col-lg-8"><div class="card stat-card"><div class="card-body"><h6>Kehadiran per Kelas (hari ini)</h6><canvas id="cClass" height="110"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card stat-card"><div class="card-body"><h6>Tren Bulanan (% hadir)</h6><canvas id="cMonth" height="220"></canvas></div></div></div>
  <div class="col-lg-6"><div class="card stat-card"><div class="card-body"><h6>Aktivitas Scan Terbaru</h6>
    <?php if (!$recent): ?><div class="text-muted small">Belum ada aktivitas.</div><?php endif; ?>
    <?php foreach ($recent as $r): ?><div class="d-flex justify-content-between border-bottom py-1 small"><span><?= e($r['full_name']) ?> <span class="text-muted">(<?= e($r['cname']) ?>)</span></span><span><span class="badge text-bg-<?= $cls($r['status']) ?>"><?= e($r['status']) ?></span> <span class="text-muted"><?= $r['scanned_at'] ? e(date('H:i', strtotime($r['scanned_at']))) : '' ?> &middot; <?= e($r['by_name']) ?></span></span></div><?php endforeach; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card stat-card"><div class="card-body"><h6>Siswa Belum Hadir Hari Ini <small class="text-muted">(15 pertama)</small></h6>
    <?php if (!$absent): ?><div class="text-muted small">Semua siswa sudah tercatat.</div><?php endif; ?>
    <?php foreach ($absent as $r): ?><div class="d-flex justify-content-between border-bottom py-1 small"><span><?= e($r['full_name']) ?></span><span class="text-muted"><?= e($r['cname']) ?></span></div><?php endforeach; ?>
  </div></div></div>
  <div class="col-12"><div class="card stat-card"><div class="card-body"><h6>Kelas dengan Kehadiran Tertinggi Hari Ini</h6>
    <?php foreach (array_slice($byClass, 0, 5) as $i => $r): ?><div class="d-flex justify-content-between border-bottom py-1 small"><span><?= $i + 1 ?>. <?= e($r['name']) ?></span><span class="fw-semibold"><?= e($r['pct']) ?>% <span class="text-muted">(<?= (int)$r['present'] ?>/<?= (int)$r['total'] ?>)</span></span></div><?php endforeach; ?>
  </div></div></div>
</div>
<?php render_footer(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const daily=<?= json_encode($daily) ?>, byClass=<?= json_encode(array_values($byClass)) ?>, byDept=<?= json_encode($byDept) ?>, monthly=<?= json_encode($monthly) ?>;
const pal={royal:'#1f4fd8',cyan:'#22d3ee',amber:'#f59e0b',red:'#ef4444',navy:'#0b1f4d'};
new Chart(cDaily,{type:'bar',data:{labels:daily.map(x=>x.d.slice(5)),datasets:[{label:'Hadir',data:daily.map(x=>+x.hadir),backgroundColor:pal.royal},{label:'Izin/Sakit',data:daily.map(x=>+x.izin),backgroundColor:pal.cyan},{label:'Alpa',data:daily.map(x=>+x.alpa),backgroundColor:pal.red}]},options:{responsive:true,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
const dk=Object.keys(byDept);new Chart(cDept,{type:'doughnut',data:{labels:dk,datasets:[{data:dk.map(k=>byDept[k].t?Math.round(byDept[k].p/byDept[k].t*1000)/10:0),backgroundColor:[pal.royal,pal.cyan,pal.navy,pal.amber,pal.red,'#8b5cf6','#10b981']}]},options:{plugins:{tooltip:{callbacks:{label:c=>c.label+': '+c.parsed+'%'}}}}});
new Chart(cClass,{type:'bar',data:{labels:byClass.map(x=>x.name),datasets:[{label:'% hadir',data:byClass.map(x=>x.pct),backgroundColor:pal.royal}]},options:{scales:{y:{beginAtZero:true,max:100}},plugins:{legend:{display:false}}}});
new Chart(cMonth,{type:'line',data:{labels:monthly.map(x=>x.ym),datasets:[{label:'% hadir',data:monthly.map(x=>+x.pct),borderColor:pal.cyan,backgroundColor:'rgba(34,211,238,.2)',fill:true,tension:.3}]},options:{scales:{y:{beginAtZero:true,max:100}},plugins:{legend:{display:false}}}});
</script>

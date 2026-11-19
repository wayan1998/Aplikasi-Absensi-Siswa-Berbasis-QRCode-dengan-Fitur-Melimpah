<?php
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/report.php';
$u = require_login(['super_admin', 'admin', 'guru']);
$pdo = db(); $f = report_filters($u, $_GET); $rows = report_rows($pdo, $u, $f);
$logo = setting('logo');
?><!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title><?= e(report_title($f)) ?></title>
<style>
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Times New Roman',serif;font-size:11pt;margin:0;padding:12mm;color:#000}
.kop{display:flex;align-items:center;gap:6mm;border-bottom:3px double #000;padding-bottom:3mm;margin-bottom:4mm}
.kop img{width:22mm;height:22mm;object-fit:contain}.kop .t{text-align:center;flex:1}
.kop h2{margin:0;font-size:15pt}.kop p{margin:0;font-size:10pt}
h3{text-align:center;margin:3mm 0 1mm}.per{text-align:center;margin:0 0 4mm;font-size:10pt}
table{width:100%;border-collapse:collapse;font-size:9.5pt}th,td{border:1px solid #000;padding:1.2mm 1.6mm}th{background:#e8ecf5}
td.n{text-align:center}.ttd{margin-top:10mm;width:60mm;margin-left:auto;text-align:center}
.bar{background:#0b1f4d;color:#fff;padding:6px;text-align:right}.bar button{padding:4px 12px}
@page{size:A4 <?= $f['group'] === 'detail' ? 'portrait' : 'landscape' ?>;margin:8mm}@media print{.bar{display:none}}
</style></head><body>
<div class="bar"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
<div class="kop"><?php if ($logo): ?><img src="<?= url('uploads/school/' . e($logo)) ?>" alt=""><?php endif; ?>
  <div class="t"><h2><?= e(mb_strtoupper(setting('school_name', 'SMK NEGERI 1 MOPUYA'))) ?></h2>
  <?php if (setting('npsn')): ?><p>NPSN: <?= e(setting('npsn')) ?></p><?php endif; ?><p><?= e(setting('address')) ?></p></div></div>
<h3>LAPORAN ABSENSI SISWA - <?= e(mb_strtoupper(report_title($f))) ?></h3>
<p class="per">Periode: <?= e(date('d/m/Y', strtotime($f['from']))) ?> s.d. <?= e(date('d/m/Y', strtotime($f['to']))) ?></p>
<table><thead><tr>
<?php if ($f['group'] === 'detail'): ?><th>No</th><th>Tanggal</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Sesi</th><th>Status</th><th>Waktu</th>
<?php else: ?><th>No</th><th>Nama</th><th>Ket.</th><th>H</th><th>T</th><th>I</th><th>S</th><th>A</th><th>D</th><th>Total</th><th>%</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $n => $r): ?><tr><td class="n"><?= $n + 1 ?></td>
<?php if ($f['group'] === 'detail'): ?><td><?= e($r['attendance_date']) ?></td><td><?= e($r['nis']) ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['class_name']) ?></td><td><?= e($r['session_name']) ?></td><td><?= e($r['status']) ?></td><td><?= $r['scanned_at'] ? e(date('H:i', strtotime($r['scanned_at']))) : '' ?></td>
<?php else: ?><td><?= e($r['label']) ?></td><td><?= e($r['extra']) ?></td><td class="n"><?= (int)$r['h'] ?></td><td class="n"><?= (int)$r['t'] ?></td><td class="n"><?= (int)$r['i'] ?></td><td class="n"><?= (int)$r['sk'] ?></td><td class="n"><?= (int)$r['al'] ?></td><td class="n"><?= (int)$r['dp'] ?></td><td class="n"><?= (int)$r['total'] ?></td><td class="n"><?= e($r['persen']) ?>%</td><?php endif; ?></tr>
<?php endforeach; ?></tbody></table>
<div class="ttd"><?= e(setting('city', 'Mopuya')) ?>, <?= e(date('d/m/Y')) ?><br>Kepala Sekolah<br><br><br><br><u><?= e(setting('principal', '................')) ?></u><?php if (setting('principal_nip')): ?><br>NIP. <?= e(setting('principal_nip')) ?><?php endif; ?></div>
</body></html>

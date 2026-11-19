<?php
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
require_once __DIR__ . '/../../app/helpers/report.php';
$u = require_login(['super_admin', 'admin', 'guru']);
$pdo = db(); $f = report_filters($u, $_GET); $rows = report_rows($pdo, $u, $f);
$depts = $pdo->query('SELECT id, code FROM departments ORDER BY code')->fetchAll();
$allowed = allowed_class_ids($u);
$classes = array_values(array_filter($pdo->query('SELECT id, name FROM classes ORDER BY name')->fetchAll(), fn($c) => $allowed === null || in_array((int)$c['id'], $allowed, true)));
$qs = http_build_query($f);
render_header('Laporan Absensi', 'reports'); ?>
<div class="card stat-card mb-3"><div class="card-body">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small">Dari</label><input type="date" name="from" value="<?= e($f['from']) ?>" class="form-control"></div>
    <div class="col-6 col-md-2"><label class="form-label small">Sampai</label><input type="date" name="to" value="<?= e($f['to']) ?>" class="form-control"></div>
    <div class="col-6 col-md-2"><label class="form-label small">Jenis rekap</label><select name="group" class="form-select"><?php foreach (['student' => 'Per siswa', 'class' => 'Per kelas', 'dept' => 'Per jurusan', 'daily' => 'Harian', 'detail' => 'Detail'] as $k => $v): ?><option value="<?= $k ?>" <?= $f['group'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <?php if ($allowed === null): ?><div class="col-6 col-md-2"><label class="form-label small">Jurusan</label><select name="dept" class="form-select"><option value="0">Semua</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $f['dept'] == $d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="col-6 col-md-2"><label class="form-label small">Kelas</label><select name="class" class="form-select"><option value="0">Semua</option><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['class'] == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select"><option value="">Semua</option><?php foreach (['HADIR','TERLAMBAT','IZIN','SAKIT','ALPA','DISPENSASI'] as $s): ?><option <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-4"><label class="form-label small">Nama / NIS siswa</label><input name="q" value="<?= e($f['q']) ?>" class="form-control" placeholder="Cari..."></div>
    <div class="col-12 col-md-8 d-flex flex-wrap gap-2 justify-content-md-end">
      <button class="btn btn-primary"><i class="bi bi-funnel"></i> Tampilkan</button>
      <a class="btn btn-outline-secondary" target="_blank" href="print.php?<?= e($qs) ?>"><i class="bi bi-file-earmark-pdf"></i> Cetak / PDF</a>
      <a class="btn btn-outline-success" href="export.php?<?= e($qs) ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
    </div>
  </form>
</div></div>
<div class="card stat-card"><div class="card-body">
  <h6><?= e(report_title($f)) ?> <small class="text-muted">(<?= e(date('d/m/Y', strtotime($f['from']))) ?> - <?= e(date('d/m/Y', strtotime($f['to']))) ?>)</small></h6>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle dt">
  <?php if ($f['group'] === 'detail'): ?>
    <thead><tr><th>Tanggal</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Sesi</th><th>Status</th><th>Waktu</th><th>Catatan</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= e($r['attendance_date']) ?></td><td><?= e($r['nis']) ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['class_name']) ?></td><td><?= e($r['session_name']) ?></td><td><?= e($r['status']) ?></td><td><?= $r['scanned_at'] ? e(date('H:i', strtotime($r['scanned_at']))) : '' ?></td><td class="small"><?= e($r['note']) ?></td></tr><?php endforeach; ?>
  <?php else: ?>
    <thead><tr><?php if ($f['group'] !== 'daily'): ?><th>#</th><?php endif; ?><th>Nama</th><th>Keterangan</th><th>Hadir</th><th>Terlambat</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Dispensasi</th><th>Total</th><th>% Hadir</th></tr></thead><tbody>
    <?php foreach ($rows as $n => $r): ?><tr><?php if ($f['group'] !== 'daily'): ?><td><?= $n + 1 ?></td><?php endif; ?><td class="fw-semibold"><?= e($r['label']) ?></td><td class="small text-muted"><?= e($r['extra']) ?></td><td><?= (int)$r['h'] ?></td><td><?= (int)$r['t'] ?></td><td><?= (int)$r['i'] ?></td><td><?= (int)$r['sk'] ?></td><td><?= (int)$r['al'] ?></td><td><?= (int)$r['dp'] ?></td><td><?= (int)$r['total'] ?></td><td><span class="badge text-bg-<?= $r['persen'] >= 90 ? 'success' : ($r['persen'] >= 75 ? 'warning' : 'danger') ?>"><?= e($r['persen']) ?>%</span></td></tr><?php endforeach; ?>
  <?php endif; ?>
  </tbody></table></div>
  <?php if ($f['group'] === 'student'): ?><div class="small text-muted">Urutan = ranking kedisiplinan (persentase hadir tertinggi; seri dilihat dari terlambat + alpa paling sedikit). Persentase dihitung dari data absensi yang tercatat.</div><?php endif; ?>
</div></div>
<?php render_footer();

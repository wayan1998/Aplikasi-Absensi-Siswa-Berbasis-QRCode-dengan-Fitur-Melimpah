<?php
require_once __DIR__ . '/../../app/helpers/attendance.php';
function render_class_dashboard(array $u): void {
    $pdo = db(); $today = date('Y-m-d');
    $ids = allowed_class_ids($u) ?? [];
    $classes = [];
    if ($ids) { $in = implode(',', array_fill(0, count($ids), '?')); $s = $pdo->prepare("SELECT id, name FROM classes WHERE id IN ($in) ORDER BY name"); $s->execute($ids); $classes = $s->fetchAll(); }
    $classId = (int)($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
    if (!in_array($classId, $ids, true)) $classId = (int)($classes[0]['id'] ?? 0);
    $stats = $classId ? class_stats($pdo, [$classId], $today) : class_stats($pdo, [], $today);
    $recent = [];
    if ($classId) {
        $r = $pdo->prepare("SELECT s.full_name, a.status, a.scanned_at, us.full_name AS by_name FROM attendance a JOIN students s ON s.id=a.student_id LEFT JOIN users us ON us.id=a.scanned_by WHERE a.class_id=? AND a.attendance_date=? ORDER BY a.id DESC LIMIT 10");
        $r->execute([$classId, $today]); $recent = $r->fetchAll();
    }
    render_header('Dashboard', 'dashboard'); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><h5 class="mb-0">Halo, <?= e($u['name']) ?></h5><small class="text-muted"><?= e(date('d-m-Y')) ?></small></div>
  <div class="d-flex gap-2">
    <?php if (count($classes) > 1): ?><form method="get"><select name="class_id" class="form-select" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $classId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></form>
    <?php elseif ($classes): ?><span class="badge text-bg-primary fs-6 align-self-center"><?= e($classes[0]['name']) ?></span><?php endif; ?>
    <?php if ($classId): ?><a class="btn btn-primary" href="<?= url('modules/scanner/index.php?class_id=' . $classId) ?>"><i class="bi bi-qr-code-scan"></i> SCAN QR CODE</a><?php endif; ?>
  </div>
</div>
<?php if (!$classes): ?><div class="alert alert-warning">Anda belum ditugaskan pada kelas mana pun.</div><?php else: ?>
<div class="row g-3">
<?php foreach ([['Total Siswa', $stats['total'], 'bi-people'], ['Sudah Hadir', $stats['HADIR'], 'bi-check2-circle'], ['Belum Hadir', $stats['belum'], 'bi-hourglass-split'], ['Terlambat', $stats['TERLAMBAT'], 'bi-alarm'], ['Izin', $stats['IZIN'], 'bi-envelope-paper'], ['Sakit', $stats['SAKIT'], 'bi-bandaid'], ['Alpa', $stats['ALPA'], 'bi-x-circle'], ['Persentase', $stats['persen'] . '%', 'bi-graph-up']] as [$l, $v, $i]): ?>
  <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body d-flex align-items-center gap-3"><div class="stat-icon"><i class="bi <?= $i ?>"></i></div><div><div class="text-muted small"><?= e($l) ?></div><div class="fs-4 fw-bold"><?= e($v) ?></div></div></div></div></div>
<?php endforeach; ?>
</div>
<div class="card stat-card mt-3"><div class="card-body"><h6>Aktivitas Scan Terbaru</h6>
  <?php if (!$recent): ?><div class="text-muted small">Belum ada scan hari ini.</div><?php endif; ?>
  <?php foreach ($recent as $r): ?><div class="d-flex justify-content-between border-bottom py-2 small"><span><?= e($r['full_name']) ?></span><span><span class="badge text-bg-<?= $r['status'] === 'HADIR' ? 'success' : ($r['status'] === 'TERLAMBAT' ? 'warning' : 'info') ?>"><?= e($r['status']) ?></span> <span class="text-muted"><?= $r['scanned_at'] ? e(date('H:i', strtotime($r['scanned_at']))) : '' ?> &middot; <?= e($r['by_name']) ?></span></span></div><?php endforeach; ?>
</div></div>
<?php endif; render_footer();
}

<?php
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
$u = require_login(['super_admin', 'admin']);
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $type = in_array($_POST['type'] ?? '', ['pagi','jam_pelajaran','siang','kegiatan','tambahan'], true) ? $_POST['type'] : 'pagi';
            $t = fn($k) => preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $_POST[$k] ?? '') ? substr($_POST[$k] . ':00', 0, 8) : null;
            [$start, $end, $late] = [$t('start_time'), $t('end_time'), $t('late_after')];
            if ($name === '' || !$start || !$end || !$late) throw new RuntimeException('Nama dan jam sesi wajib diisi.');
            if ($end <= $start) throw new RuntimeException('Jam selesai harus setelah jam mulai.');
            if ($late < $start || $late > $end) throw new RuntimeException('Batas terlambat harus berada di antara jam mulai dan selesai.');
            $days = implode(',', array_values(array_intersect(['1','2','3','4','5','6','7'], $_POST['days'] ?? [])));
            if ($days === '') throw new RuntimeException('Pilih minimal satu hari aktif.');
            $d = [$name, $type, $start, $end, $late, (int)($_POST['department_id'] ?? 0) ?: null, (int)($_POST['class_id'] ?? 0) ?: null, $days, isset($_POST['is_active']) ? 1 : 0];
            if ($id) $pdo->prepare('UPDATE attendance_sessions SET name=?,type=?,start_time=?,end_time=?,late_after=?,department_id=?,class_id=?,active_days=?,is_active=? WHERE id=?')->execute([...$d, $id]);
            else $pdo->prepare('INSERT INTO attendance_sessions (name,type,start_time,end_time,late_after,department_id,class_id,active_days,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([...$d, $u['id']]);
            log_activity('save', 'attendance_sessions', $id ?: $pdo->lastInsertId(), $name);
            flash('ok', 'Sesi disimpan.');
        } elseif ($act === 'delete') {
            $id = (int)$_POST['id'];
            $c = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE session_id=?'); $c->execute([$id]);
            if ($c->fetchColumn() > 0) throw new RuntimeException('Sesi sudah memiliki data absensi. Nonaktifkan saja.');
            $pdo->prepare('DELETE FROM attendance_sessions WHERE id=?')->execute([$id]);
            flash('ok', 'Sesi dihapus.');
        }
    } catch (Throwable $e) { flash('err', $e instanceof PDOException ? 'Kesalahan database.' : $e->getMessage()); }
    redirect('modules/attendance/sessions.php');
}
$depts = $pdo->query('SELECT id, code FROM departments ORDER BY code')->fetchAll();
$classes = $pdo->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();
$rows = $pdo->query('SELECT s.*, d.code AS dcode, c.name AS cname FROM attendance_sessions s LEFT JOIN departments d ON d.id=s.department_id LEFT JOIN classes c ON c.id=s.class_id ORDER BY s.start_time')->fetchAll();
$dn = ['1'=>'Sen','2'=>'Sel','3'=>'Rab','4'=>'Kam','5'=>'Jum','6'=>'Sab','7'=>'Min'];
render_header('Sesi Absensi', 'sessions'); ?>
<div class="card stat-card"><div class="card-body">
  <div class="d-flex justify-content-between mb-3"><h5 class="mb-0">Sesi Absensi</h5><button class="btn btn-primary" onclick="openForm()"><i class="bi bi-plus-lg"></i> Tambah Sesi</button></div>
  <div class="table-responsive"><table class="table table-hover dt align-middle"><thead><tr><th>Nama</th><th>Jenis</th><th>Jam</th><th>Terlambat setelah</th><th>Cakupan</th><th>Hari</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td class="fw-semibold"><?= e($r['name']) ?></td><td><?= e($r['type']) ?></td>
    <td><?= substr($r['start_time'], 0, 5) ?>-<?= substr($r['end_time'], 0, 5) ?></td><td><?= substr($r['late_after'], 0, 5) ?></td>
    <td><?= e($r['cname'] ?: ($r['dcode'] ?: 'Semua kelas')) ?></td>
    <td class="small"><?= e(implode(' ', array_map(fn($x) => $dn[$x], explode(',', $r['active_days'])))) ?></td>
    <td><span class="badge text-bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
    <td class="text-end text-nowrap">
      <button class="btn btn-sm btn-outline-primary" onclick='openForm(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline confirm-form" data-msg="Hapus sesi ini?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </td></tr><?php endforeach; ?>
  </tbody></table></div>
</div></div>
<div class="modal fade" id="fm"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
  <div class="modal-header"><h5 class="modal-title">Sesi Absensi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-8"><label class="form-label">Nama Sesi</label><input name="name" id="f_name" class="form-control" required></div>
    <div class="col-4"><label class="form-label">Jenis</label><select name="type" id="f_type" class="form-select"><option value="pagi">Pagi</option><option value="jam_pelajaran">Jam pelajaran</option><option value="siang">Siang</option><option value="kegiatan">Kegiatan</option><option value="tambahan">Tambahan</option></select></div>
    <div class="col-4"><label class="form-label">Mulai</label><input type="time" name="start_time" id="f_start" class="form-control" required></div>
    <div class="col-4"><label class="form-label">Selesai</label><input type="time" name="end_time" id="f_end" class="form-control" required></div>
    <div class="col-4"><label class="form-label">Batas terlambat</label><input type="time" name="late_after" id="f_late" class="form-control" required></div>
    <div class="col-6"><label class="form-label">Jurusan</label><select name="department_id" id="f_dept" class="form-select"><option value="0">Semua</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6"><label class="form-label">Kelas</label><select name="class_id" id="f_class" class="form-select"><option value="0">Semua</option><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><label class="form-label d-block">Hari aktif</label><?php foreach ($dn as $k => $v): ?><div class="form-check form-check-inline"><input class="form-check-input day" type="checkbox" name="days[]" value="<?= $k ?>" id="d<?= $k ?>"><label class="form-check-label" for="d<?= $k ?>"><?= $v ?></label></div><?php endforeach; ?></div>
    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="f_act" checked><label class="form-check-label" for="f_act">Aktif</label></div></div>
  </div></div><div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openForm(r){r=r||{};f_id.value=r.id||'';f_name.value=r.name||'';f_type.value=r.type||'pagi';f_start.value=(r.start_time||'06:30').slice(0,5);f_end.value=(r.end_time||'09:00').slice(0,5);f_late.value=(r.late_after||'07:15').slice(0,5);
f_dept.value=r.department_id||0;f_class.value=r.class_id||0;const ds=(r.active_days||'1,2,3,4,5').split(',');document.querySelectorAll('.day').forEach(c=>c.checked=ds.includes(c.value));f_act.checked=r.id?r.is_active==1:true;new bootstrap.Modal(fm).show()}
</script>

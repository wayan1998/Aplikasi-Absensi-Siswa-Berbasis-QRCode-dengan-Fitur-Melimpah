<?php
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
require_once __DIR__ . '/../../app/helpers/qr.php';
$u = require_login(['super_admin', 'admin']);
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $grade = $_POST['grade_level'] ?? ''; $name = trim($_POST['name'] ?? ''); $dept = (int)($_POST['department_id'] ?? 0);
            if (!in_array($grade, ['X','XI','XII'], true) || $name === '' || !$dept) throw new RuntimeException('Data kelas tidak lengkap.');
            $ay = (int)($_POST['academic_year_id'] ?? 0) ?: active_ay_id($pdo);
            $d = [$dept, $ay, $grade, $name, isset($_POST['is_active']) ? 1 : 0];
            if ($id) $pdo->prepare('UPDATE classes SET department_id=?,academic_year_id=?,grade_level=?,name=?,is_active=? WHERE id=?')->execute([...$d, $id]);
            else $pdo->prepare('INSERT INTO classes (department_id,academic_year_id,grade_level,name,is_active) VALUES (?,?,?,?,?)')->execute($d);
            log_activity($id ? 'update' : 'create', 'classes', $id ?: $pdo->lastInsertId(), $name);
            flash('ok', 'Kelas disimpan.');
        } elseif ($act === 'delete') {
            $id = (int)$_POST['id'];
            $c = $pdo->prepare('SELECT COUNT(*) FROM students WHERE class_id=?'); $c->execute([$id]);
            if ($c->fetchColumn() > 0) throw new RuntimeException('Kelas masih memiliki siswa. Pindahkan siswa terlebih dahulu.');
            $a = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE class_id=?'); $a->execute([$id]);
            if ($a->fetchColumn() > 0) throw new RuntimeException('Kelas memiliki riwayat absensi, tidak dapat dihapus. Nonaktifkan saja.');
            $pdo->prepare('DELETE FROM classes WHERE id=?')->execute([$id]);
            log_activity('delete', 'classes', $id);
            flash('ok', 'Kelas dihapus.');
        } elseif ($act === 'promote') {
            // Naik kelas: pindahkan semua siswa aktif dari kelas asal ke kelas tujuan
            $from = (int)$_POST['from_class']; $to = (int)$_POST['to_class'];
            if (!$from || !$to || $from === $to) throw new RuntimeException('Pilih kelas asal dan tujuan yang berbeda.');
            $st = $pdo->prepare("UPDATE students SET class_id=? WHERE class_id=? AND status='aktif'"); $st->execute([$to, $from]);
            log_activity('promote', 'classes', $from, "ke $to, " . $st->rowCount() . ' siswa');
            flash('ok', $st->rowCount() . ' siswa dipindahkan.');
        }
    } catch (PDOException $e) { flash('err', $e->getCode() === '23000' ? 'Nama kelas sudah ada pada tahun ajaran ini.' : 'Kesalahan database.'); }
    catch (Throwable $e) { flash('err', $e->getMessage()); }
    redirect('modules/classes/index.php');
}
$depts = $pdo->query('SELECT id, code FROM departments WHERE is_active=1 ORDER BY code')->fetchAll();
$ays = $pdo->query('SELECT id, name, is_active FROM academic_years ORDER BY start_date DESC')->fetchAll();
$rows = $pdo->query("SELECT c.*, d.code AS dcode, ay.name AS ayname, (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id AND s.status='aktif') AS n_st FROM classes c JOIN departments d ON d.id=c.department_id LEFT JOIN academic_years ay ON ay.id=c.academic_year_id ORDER BY d.code, FIELD(c.grade_level,'X','XI','XII'), c.name")->fetchAll();
render_header('Manajemen Kelas', 'classes'); ?>
<div class="card stat-card"><div class="card-body">
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><h5 class="mb-0">Daftar Kelas</h5>
    <div class="d-flex gap-2"><button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#pm"><i class="bi bi-arrow-up-circle"></i> Naik Kelas</button>
    <button class="btn btn-primary" onclick="openForm()"><i class="bi bi-plus-lg"></i> Tambah Kelas</button></div></div>
  <div class="table-responsive"><table class="table table-hover dt align-middle"><thead><tr><th>Kelas</th><th>Jurusan</th><th>Tingkat</th><th>Tahun Ajaran</th><th>Siswa</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td class="fw-semibold"><?= e($r['name']) ?></td><td><?= e($r['dcode']) ?></td><td><?= e($r['grade_level']) ?></td><td><?= e($r['ayname']) ?></td><td><?= (int)$r['n_st'] ?></td>
    <td><span class="badge text-bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
    <td class="text-end text-nowrap">
      <a class="btn btn-sm btn-outline-success" href="<?= url('modules/qrcode/cards.php?class_id=' . (int)$r['id']) ?>" target="_blank" title="Cetak kartu"><i class="bi bi-printer"></i></a>
      <button class="btn btn-sm btn-outline-primary" onclick='openForm(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline confirm-form" data-msg="Hapus kelas ini?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </td></tr><?php endforeach; ?>
  </tbody></table></div>
</div></div>
<div class="modal fade" id="fm"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
  <div class="modal-header"><h5 class="modal-title">Kelas</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-3">
    <div><label class="form-label">Jurusan</label><select name="department_id" id="f_dept" class="form-select" required><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['code']) ?></option><?php endforeach; ?></select></div>
    <div class="row g-2"><div class="col-5"><label class="form-label">Tingkat</label><select name="grade_level" id="f_grade" class="form-select"><option>X</option><option>XI</option><option>XII</option></select></div>
    <div class="col-7"><label class="form-label">Nama Kelas</label><input name="name" id="f_name" class="form-control" placeholder="X TJKT 1" required></div></div>
    <div><label class="form-label">Tahun Ajaran</label><select name="academic_year_id" id="f_ay" class="form-select"><?php foreach ($ays as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $a['is_active'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="f_act" checked><label class="form-check-label" for="f_act">Aktif</label></div>
  </div><div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<div class="modal fade" id="pm"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="promote">
  <div class="modal-header"><h5 class="modal-title">Naik Kelas / Pindah Massal</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-3">
    <div><label class="form-label">Dari kelas</label><select name="from_class" class="form-select"><?php foreach ($rows as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label">Ke kelas</label><select name="to_class" class="form-select"><?php foreach ($rows as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
    <div class="small text-muted">Semua siswa aktif di kelas asal dipindahkan. QR Code siswa tidak berubah.</div>
  </div><div class="modal-footer"><button class="btn btn-primary">Proses</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openForm(r){r=r||{};f_id.value=r.id||'';if(r.department_id)f_dept.value=r.department_id;f_grade.value=r.grade_level||'X';f_name.value=r.name||'';if(r.academic_year_id)f_ay.value=r.academic_year_id;f_act.checked=r.id?r.is_active==1:true;new bootstrap.Modal(fm).show()}
</script>

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
            $code = strtoupper(trim($_POST['code'] ?? '')); $name = trim($_POST['name'] ?? '');
            if ($code === '' || $name === '') throw new RuntimeException('Kode dan nama wajib diisi.');
            $d = [$code, $name, trim($_POST['head_name'] ?? '') ?: null, trim($_POST['description'] ?? '') ?: null, isset($_POST['is_active']) ? 1 : 0];
            if ($id) { $pdo->prepare('UPDATE departments SET code=?,name=?,head_name=?,description=?,is_active=? WHERE id=?')->execute([...$d, $id]); }
            else { $pdo->prepare('INSERT INTO departments (code,name,head_name,description,is_active) VALUES (?,?,?,?,?)')->execute($d); }
            log_activity($id ? 'update' : 'create', 'departments', $id ?: $pdo->lastInsertId(), $code);
            flash('ok', 'Jurusan disimpan.');
        } elseif ($act === 'delete') {
            $id = (int)$_POST['id'];
            $c = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE department_id=?'); $c->execute([$id]);
            if ($c->fetchColumn() > 0) throw new RuntimeException('Jurusan masih memiliki kelas. Hapus/pindahkan kelas terlebih dahulu.');
            $pdo->prepare('DELETE FROM departments WHERE id=?')->execute([$id]);
            log_activity('delete', 'departments', $id);
            flash('ok', 'Jurusan dihapus.');
        }
    } catch (PDOException $e) { flash('err', $e->getCode() === '23000' ? 'Kode jurusan sudah digunakan.' : 'Kesalahan database.'); }
    catch (Throwable $e) { flash('err', $e->getMessage()); }
    redirect('modules/departments/index.php');
}
$rows = $pdo->query('SELECT d.*, (SELECT COUNT(*) FROM classes c WHERE c.department_id=d.id) AS n_class FROM departments d ORDER BY d.code')->fetchAll();
render_header('Manajemen Jurusan', 'departments'); ?>
<div class="card stat-card"><div class="card-body">
  <div class="d-flex justify-content-between mb-3"><h5 class="mb-0">Daftar Jurusan</h5>
    <button class="btn btn-primary" onclick="openForm()"><i class="bi bi-plus-lg"></i> Tambah Jurusan</button></div>
  <div class="table-responsive"><table class="table table-hover dt align-middle"><thead><tr><th>Kode</th><th>Nama</th><th>Kepala Program</th><th>Kelas</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td class="fw-semibold"><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><?= e($r['head_name']) ?></td><td><?= (int)$r['n_class'] ?></td>
    <td><span class="badge text-bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
    <td class="text-end text-nowrap">
      <button class="btn btn-sm btn-outline-primary" onclick='openForm(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline confirm-form" data-msg="Hapus jurusan ini?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </td></tr><?php endforeach; ?>
  </tbody></table></div>
</div></div>
<div class="modal fade" id="fm"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
  <div class="modal-header"><h5 class="modal-title">Jurusan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-3">
    <div><label class="form-label">Kode</label><input name="code" id="f_code" class="form-control" required maxlength="20"></div>
    <div><label class="form-label">Nama Jurusan</label><input name="name" id="f_name" class="form-control" required></div>
    <div><label class="form-label">Kepala Program Keahlian</label><input name="head_name" id="f_head" class="form-control"></div>
    <div><label class="form-label">Deskripsi</label><textarea name="description" id="f_desc" class="form-control" rows="2"></textarea></div>
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="f_act" checked><label class="form-check-label" for="f_act">Aktif</label></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openForm(r){r=r||{};f_id.value=r.id||'';f_code.value=r.code||'';f_name.value=r.name||'';f_head.value=r.head_name||'';f_desc.value=r.description||'';f_act.checked=r.id?r.is_active==1:true;new bootstrap.Modal(fm).show()}
</script>

<?php
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
require_once __DIR__ . '/../../app/helpers/qr.php';
$u = require_login(['super_admin', 'admin']);
$pdo = db(); $ay = active_ay_id($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['full_name'] ?? ''); $username = trim($_POST['username'] ?? ''); $pass = (string)($_POST['password'] ?? '');
            if ($name === '' || $username === '') throw new RuntimeException('Nama dan username wajib diisi.');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) throw new RuntimeException('Username 3-50 karakter (huruf, angka, . _ -).');
            $pdo->beginTransaction();
            if ($id) {
                $t = $pdo->prepare('SELECT user_id FROM teachers WHERE id=?'); $t->execute([$id]); $uid = (int)$t->fetchColumn();
                $pdo->prepare('UPDATE users SET username=?, full_name=? WHERE id=?')->execute([$username, $name, $uid]);
                if ($pass !== '') { if (strlen($pass) < 6) throw new RuntimeException('Password minimal 6 karakter.'); $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]); }
                $pdo->prepare('UPDATE teachers SET nip=?, full_name=?, phone=?, is_active=? WHERE id=?')->execute([trim($_POST['nip'] ?? '') ?: null, $name, trim($_POST['phone'] ?? '') ?: null, isset($_POST['is_active']) ? 1 : 0, $id]);
                $pdo->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([isset($_POST['is_active']) ? 1 : 0, $uid]);
            } else {
                if (strlen($pass) < 6) throw new RuntimeException('Password minimal 6 karakter.');
                $pdo->prepare("INSERT INTO users (role_id,username,password_hash,full_name) SELECT id,?,?,? FROM roles WHERE code='guru'")->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $name]);
                $uid = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO teachers (user_id,nip,full_name,phone) VALUES (?,?,?,?)')->execute([$uid, trim($_POST['nip'] ?? '') ?: null, $name, trim($_POST['phone'] ?? '') ?: null]);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM class_assignments WHERE user_id=?')->execute([$uid]);
            $ins = $pdo->prepare('INSERT INTO class_assignments (user_id,class_id,academic_year_id) VALUES (?,?,?)');
            foreach (array_unique(array_map('intval', $_POST['classes'] ?? [])) as $cid) $ins->execute([$uid, $cid, $ay]);
            $pdo->commit();
            log_activity('save', 'teachers', $id, $name);
            flash('ok', 'Data guru disimpan.');
        } elseif ($act === 'delete') {
            $id = (int)$_POST['id'];
            $pdo->beginTransaction();
            $t = $pdo->prepare('SELECT user_id FROM teachers WHERE id=?'); $t->execute([$id]); $uid = $t->fetchColumn();
            $pdo->prepare('DELETE FROM teachers WHERE id=?')->execute([$id]);
            if ($uid) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            $pdo->commit();
            log_activity('delete', 'teachers', $id);
            flash('ok', 'Guru dihapus.');
        }
    } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('err', $e->getCode() === '23000' ? 'Username atau NIP sudah digunakan, atau data masih dipakai.' : 'Kesalahan database.'); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('err', $e->getMessage()); }
    redirect('modules/teachers/index.php');
}
$classes = $pdo->query('SELECT id,name FROM classes WHERE is_active=1 ORDER BY name')->fetchAll();
$rows = $pdo->query("SELECT t.*, u.username, GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS class_names, GROUP_CONCAT(c.id) AS class_ids FROM teachers t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN class_assignments ca ON ca.user_id=t.user_id LEFT JOIN classes c ON c.id=ca.class_id GROUP BY t.id ORDER BY t.full_name")->fetchAll();
render_header('Manajemen Guru', 'teachers'); ?>
<div class="card stat-card"><div class="card-body">
  <div class="d-flex justify-content-between mb-3"><h5 class="mb-0">Daftar Guru</h5><button class="btn btn-primary" onclick="openForm()"><i class="bi bi-plus-lg"></i> Tambah Guru</button></div>
  <div class="table-responsive"><table class="table table-hover dt align-middle"><thead><tr><th>Nama</th><th>NIP</th><th>Username</th><th>Kelas Diampu</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td class="fw-semibold"><?= e($r['full_name']) ?></td><td><?= e($r['nip']) ?></td><td><?= e($r['username']) ?></td><td class="small"><?= e($r['class_names']) ?></td>
    <td><span class="badge text-bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
    <td class="text-end text-nowrap">
      <button class="btn btn-sm btn-outline-primary" onclick='openForm(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline confirm-form" data-msg="Hapus guru dan akunnya?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </td></tr><?php endforeach; ?>
  </tbody></table></div>
</div></div>
<div class="modal fade" id="fm"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
  <div class="modal-header"><h5 class="modal-title">Guru</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-3">
    <div><label class="form-label">Nama Lengkap</label><input name="full_name" id="f_name" class="form-control" required></div>
    <div class="row g-2"><div class="col-6"><label class="form-label">NIP</label><input name="nip" id="f_nip" class="form-control"></div><div class="col-6"><label class="form-label">No. HP</label><input name="phone" id="f_phone" class="form-control"></div></div>
    <div class="row g-2"><div class="col-6"><label class="form-label">Username</label><input name="username" id="f_user" class="form-control" required></div><div class="col-6"><label class="form-label">Password <small class="text-muted" id="f_hint"></small></label><input type="password" name="password" id="f_pass" class="form-control" autocomplete="new-password"></div></div>
    <div><label class="form-label">Kelas yang diampu</label><select name="classes[]" id="f_classes" class="form-select" multiple size="6"><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select><div class="form-text">Tahan Ctrl/Cmd untuk memilih lebih dari satu.</div></div>
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="f_act" checked><label class="form-check-label" for="f_act">Aktif</label></div>
  </div><div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openForm(r){r=r||{};f_id.value=r.id||'';f_name.value=r.full_name||'';f_nip.value=r.nip||'';f_phone.value=r.phone||'';f_user.value=r.username||'';f_pass.value='';f_pass.required=!r.id;f_hint.textContent=r.id?'(kosongkan jika tidak diubah)':'';
var ids=(r.class_ids||'').split(',');[...f_classes.options].forEach(o=>o.selected=ids.includes(o.value));f_act.checked=r.id?r.is_active==1:true;new bootstrap.Modal(fm).show()}
</script>

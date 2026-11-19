<?php
// Tahun ajaran & semester. Hanya satu tahun ajaran dan satu semester yang aktif pada satu waktu.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
$u = require_login(['super_admin', 'admin']);
$pdo = db();

function valid_date(?string $d): bool { $x = DateTime::createFromFormat('Y-m-d', (string)$d); return $x && $x->format('Y-m-d') === $d; }

// Aktifkan satu semester (beserta tahun ajarannya), nonaktifkan yang lain.
function activate_semester(PDO $pdo, int $semId): void {
    $s = $pdo->prepare('SELECT academic_year_id FROM semesters WHERE id=?'); $s->execute([$semId]);
    $ay = $s->fetchColumn(); if (!$ay) throw new RuntimeException('Semester tidak ditemukan.');
    $pdo->prepare('UPDATE academic_years SET is_active = (id = ?)')->execute([$ay]);
    $pdo->prepare('UPDATE semesters SET is_active = (id = ?)')->execute([$semId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? '');
            $gs = $_POST['g_start'] ?? ''; $ge = $_POST['g_end'] ?? ''; $ns = $_POST['n_start'] ?? ''; $ne = $_POST['n_end'] ?? '';
            if (!preg_match('/^(\d{4})\/(\d{4})$/', $name, $m) || (int)$m[2] !== (int)$m[1] + 1) throw new RuntimeException('Nama tahun ajaran harus berformat 2026/2027.');
            foreach ([$gs, $ge, $ns, $ne] as $d) if (!valid_date($d)) throw new RuntimeException('Semua tanggal semester wajib diisi dengan benar.');
            if ($gs >= $ge || $ns >= $ne) throw new RuntimeException('Tanggal selesai semester harus setelah tanggal mulai.');
            if ($ge >= $ns) throw new RuntimeException('Semester Genap harus dimulai setelah Semester Ganjil berakhir.');
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE academic_years SET name=?, start_date=?, end_date=? WHERE id=?')->execute([$name, $gs, $ne, $id]);
            } else {
                $pdo->prepare('INSERT INTO academic_years (name,start_date,end_date,is_active) VALUES (?,?,?,0)')->execute([$name, $gs, $ne]);
                $id = (int)$pdo->lastInsertId();
            }
            foreach ([['Ganjil', $gs, $ge], ['Genap', $ns, $ne]] as [$sn, $a, $b]) {
                $up = $pdo->prepare('UPDATE semesters SET start_date=?, end_date=? WHERE academic_year_id=? AND name=?');
                $up->execute([$a, $b, $id, $sn]);
                $ex = $pdo->prepare('SELECT COUNT(*) FROM semesters WHERE academic_year_id=? AND name=?'); $ex->execute([$id, $sn]);
                if (!(int)$ex->fetchColumn()) $pdo->prepare('INSERT INTO semesters (academic_year_id,name,start_date,end_date,is_active) VALUES (?,?,?,?,0)')->execute([$id, $sn, $a, $b]);
            }
            $pdo->commit();
            log_activity('save', 'academic_years', $id, $name);
            flash('ok', 'Tahun ajaran disimpan.');
        } elseif ($act === 'activate_year') {
            $id = (int)$_POST['id'];
            $today = date('Y-m-d');
            $pick = $pdo->prepare('SELECT id FROM semesters WHERE academic_year_id=? ORDER BY (? BETWEEN start_date AND end_date) DESC, start_date ASC LIMIT 1');
            $pick->execute([$id, $today]);
            $sem = (int)$pick->fetchColumn(); if (!$sem) throw new RuntimeException('Tahun ajaran belum memiliki semester.');
            $pdo->beginTransaction(); activate_semester($pdo, $sem); $pdo->commit();
            log_activity('activate', 'academic_years', $id);
            flash('ok', 'Tahun ajaran diaktifkan.');
        } elseif ($act === 'activate_semester') {
            $pdo->beginTransaction(); activate_semester($pdo, (int)$_POST['id']); $pdo->commit();
            log_activity('activate', 'semesters', (int)$_POST['id']);
            flash('ok', 'Semester diaktifkan.');
        } elseif ($act === 'delete') {
            $id = (int)$_POST['id'];
            $a = $pdo->prepare('SELECT is_active FROM academic_years WHERE id=?'); $a->execute([$id]);
            if ($a->fetchColumn()) throw new RuntimeException('Tahun ajaran aktif tidak dapat dihapus. Aktifkan tahun ajaran lain terlebih dahulu.');
            $c = $pdo->prepare('SELECT (SELECT COUNT(*) FROM classes WHERE academic_year_id=?) + (SELECT COUNT(*) FROM class_assignments WHERE academic_year_id=?)');
            $c->execute([$id, $id]);
            if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Tahun ajaran masih dipakai oleh data kelas/penugasan, sehingga tidak dapat dihapus.');
            $pdo->prepare('DELETE FROM academic_years WHERE id=?')->execute([$id]);   // semester ikut terhapus (CASCADE)
            log_activity('delete', 'academic_years', $id);
            flash('ok', 'Tahun ajaran dihapus.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e instanceof PDOException ? ($e->getCode() === '23000' ? 'Nama tahun ajaran sudah ada.' : 'Kesalahan database.') : $e->getMessage();
        flash('err', $msg);
    }
    redirect('modules/settings/academic.php');
}

$years = $pdo->query('SELECT ay.*, (SELECT COUNT(*) FROM classes c WHERE c.academic_year_id=ay.id) AS n_class FROM academic_years ay ORDER BY ay.start_date DESC')->fetchAll();
$sems = [];
foreach ($pdo->query('SELECT * FROM semesters ORDER BY start_date')->fetchAll() as $s) $sems[$s['academic_year_id']][$s['name']] = $s;

render_header('Tahun Ajaran & Semester', 'academic'); settings_tabs('academic'); ?>
<div class="card stat-card"><div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div><h5 class="mb-0">Tahun Ajaran</h5><small class="text-muted">Tahun ajaran & semester aktif dipakai sebagai acuan data kelas baru.</small></div>
    <button class="btn btn-primary" onclick="openForm()"><i class="bi bi-plus-lg"></i> Tambah</button></div>
  <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Tahun Ajaran</th><th>Semester Ganjil</th><th>Semester Genap</th><th>Kelas</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($years as $y): $g = $sems[$y['id']]['Ganjil'] ?? null; $n = $sems[$y['id']]['Genap'] ?? null; ?>
    <tr>
      <td class="fw-semibold"><?= e($y['name']) ?></td>
      <?php foreach ([$g, $n] as $s): ?><td class="small">
        <?php if ($s): ?><?= e(tgl_id($s['start_date'])) ?><br><span class="text-muted">s.d. <?= e(tgl_id($s['end_date'])) ?></span><br>
          <?php if ($s['is_active']): ?><span class="badge text-bg-success">Semester aktif</span>
          <?php else: ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="activate_semester"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-link btn-sm p-0">Aktifkan semester</button></form><?php endif; ?>
        <?php else: ?><span class="text-muted">-</span><?php endif; ?></td><?php endforeach; ?>
      <td><?= (int)$y['n_class'] ?></td>
      <td><?= $y['is_active'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Tidak aktif</span>' ?></td>
      <td class="text-end text-nowrap">
        <?php if (!$y['is_active']): ?><form method="post" class="d-inline confirm-form" data-msg="Jadikan tahun ajaran ini aktif?"><?= csrf_field() ?><input type="hidden" name="action" value="activate_year"><input type="hidden" name="id" value="<?= (int)$y['id'] ?>"><button class="btn btn-sm btn-outline-success" title="Aktifkan"><i class="bi bi-check2-circle"></i></button></form><?php endif; ?>
        <button class="btn btn-sm btn-outline-primary" onclick='openForm(<?= json_encode(['id' => $y['id'], 'name' => $y['name'], 'g_start' => $g['start_date'] ?? '', 'g_end' => $g['end_date'] ?? '', 'n_start' => $n['start_date'] ?? '', 'n_end' => $n['end_date'] ?? ''], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
        <?php if (!$y['is_active']): ?><form method="post" class="d-inline confirm-form" data-msg="Hapus tahun ajaran beserta semesternya?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$y['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$years): ?><tr><td colspan="6" class="text-center text-muted py-4">Belum ada tahun ajaran.</td></tr><?php endif; ?>
  </tbody></table></div>
</div></div>
<div class="modal fade" id="fm"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
  <div class="modal-header"><h5 class="modal-title">Tahun Ajaran</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label">Nama (contoh 2027/2028)</label><input name="name" id="f_name" class="form-control" required pattern="\d{4}/\d{4}" placeholder="2027/2028"></div>
    <div class="col-6"><label class="form-label">Ganjil - mulai</label><input type="date" name="g_start" id="f_gs" class="form-control" required></div>
    <div class="col-6"><label class="form-label">Ganjil - selesai</label><input type="date" name="g_end" id="f_ge" class="form-control" required></div>
    <div class="col-6"><label class="form-label">Genap - mulai</label><input type="date" name="n_start" id="f_ns" class="form-control" required></div>
    <div class="col-6"><label class="form-label">Genap - selesai</label><input type="date" name="n_end" id="f_ne" class="form-control" required></div>
    <div class="col-12 small text-muted">Tahun ajaran baru dibuat tidak aktif. Gunakan tombol centang pada daftar untuk mengaktifkannya.</div>
  </div></div><div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openForm(r){r=r||{};f_id.value=r.id||'';f_name.value=r.name||'';
  var y=new Date().getFullYear();
  f_gs.value=r.g_start||(y+'-07-13');f_ge.value=r.g_end||(y+'-12-19');f_ns.value=r.n_start||((y+1)+'-01-04');f_ne.value=r.n_end||((y+1)+'-06-26');
  if(!r.id)f_name.value=y+'/'+(y+1);
  new bootstrap.Modal(fm).show()}
</script>

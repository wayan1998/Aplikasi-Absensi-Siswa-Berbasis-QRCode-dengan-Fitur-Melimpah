<?php
// Hari libur: pada tanggal terdaftar, sesi absensi tidak berlaku (lihat sessions_for_class) sehingga scan ditolak.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
$u = require_login(['super_admin', 'admin']);
$pdo = db();
const HOLIDAY_MAX_RANGE = 62;   // hari, per sekali input rentang

function hol_date(?string $d): bool { $x = DateTime::createFromFormat('Y-m-d', (string)$d); return $x && $x->format('Y-m-d') === $d; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $year = (int)($_POST['year'] ?? date('Y'));
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? '');
            $d1 = $_POST['date'] ?? ''; $d2 = ($_POST['end_date'] ?? '') ?: $d1;
            if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Nama hari libur wajib diisi (maks 120 karakter).');
            if (!hol_date($d1) || !hol_date($d2)) throw new RuntimeException('Tanggal tidak valid.');
            if ($d2 < $d1) throw new RuntimeException('Tanggal akhir tidak boleh sebelum tanggal mulai.');
            $year = (int)substr($d1, 0, 4);
            if ($id) {   // ubah satu tanggal
                $pdo->prepare('UPDATE holidays SET holiday_date=?, name=? WHERE id=?')->execute([$d1, $name, $id]);
                flash('ok', 'Hari libur diperbarui.');
            } else {
                $days = []; $skipWeekend = isset($_POST['skip_weekend']);
                $span = (new DateTime($d1))->diff(new DateTime($d2))->days + 1;
                if ($span > HOLIDAY_MAX_RANGE) throw new RuntimeException('Rentang maksimal ' . HOLIDAY_MAX_RANGE . ' hari per input.');
                for ($d = new DateTime($d1), $end = new DateTime($d2); $d <= $end; $d->modify('+1 day')) {
                    if ($skipWeekend && (int)$d->format('N') >= 6) continue;
                    $days[] = $d->format('Y-m-d');
                }
                if (!$days) throw new RuntimeException('Tidak ada tanggal yang ditambahkan (semua jatuh pada akhir pekan).');
                $ins = $pdo->prepare('INSERT IGNORE INTO holidays (holiday_date, name) VALUES (?, ?)');
                $added = 0; $pdo->beginTransaction();
                foreach ($days as $d) { $ins->execute([$d, $name]); $added += $ins->rowCount(); }
                $pdo->commit();
                $skipped = count($days) - $added;
                if (!$added && count($days) === 1) throw new RuntimeException('Tanggal tersebut sudah terdaftar sebagai hari libur.');
                $msg = "$added hari libur ditambahkan" . ($skipped ? " ($skipped tanggal sudah ada, dilewati)." : '.');
                $c = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE attendance_date BETWEEN ? AND ?'); $c->execute([$d1, $d2]);
                if ((int)$c->fetchColumn() > 0) $msg .= ' Perhatian: sudah ada data absensi pada rentang ini (tidak diubah).';
                flash('ok', $msg);
            }
            log_activity('save', 'holidays', $id ?: null, "$name $d1 - $d2");
        } elseif ($act === 'delete') {
            $pdo->prepare('DELETE FROM holidays WHERE id=?')->execute([(int)$_POST['id']]);
            log_activity('delete', 'holidays', (int)$_POST['id']);
            flash('ok', 'Hari libur dihapus.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('err', $e instanceof PDOException ? ($e->getCode() === '23000' ? 'Tanggal tersebut sudah terdaftar sebagai hari libur.' : 'Kesalahan database.') : $e->getMessage());
    }
    redirect('modules/settings/holidays.php?year=' . $year);
}

$year = (int)($_GET['year'] ?? date('Y'));
$yrs = $pdo->query('SELECT DISTINCT YEAR(holiday_date) FROM holidays')->fetchAll(PDO::FETCH_COLUMN);
$yrs = array_values(array_unique(array_merge(array_map('intval', $yrs), [(int)date('Y'), (int)date('Y') + 1, $year]))); sort($yrs);
$st = $pdo->prepare('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date');
$st->execute(["$year-01-01", "$year-12-31"]); $rows = $st->fetchAll();

render_header('Hari Libur', 'holidays'); settings_tabs('holidays'); ?>
<div class="card stat-card"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h5 class="mb-0">Hari Libur</h5><small class="text-muted">Pada tanggal libur, scan QR dan sesi absensi otomatis ditolak.</small></div>
    <div class="d-flex gap-2">
      <form method="get"><select name="year" class="form-select" onchange="this.form.submit()"><?php foreach ($yrs as $y): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></form>
      <button class="btn btn-primary" onclick="openForm()"><i class="bi bi-plus-lg"></i> Tambah</button>
    </div>
  </div>
  <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Tanggal</th><th>Keterangan</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td data-order="<?= e($r['holiday_date']) ?>"><?= e(tgl_id($r['holiday_date'], true)) ?></td>
    <td class="fw-semibold"><?= e($r['name']) ?></td>
    <td class="text-end text-nowrap">
      <button class="btn btn-sm btn-outline-primary" onclick='openForm(<?= json_encode(['id' => $r['id'], 'date' => $r['holiday_date'], 'name' => $r['name']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline confirm-form" data-msg="Hapus hari libur ini?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="year" value="<?= $year ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="3" class="text-center text-muted py-4">Belum ada hari libur pada tahun <?= $year ?>.</td></tr><?php endif; ?>
  </tbody></table></div>
</div></div>
<div class="modal fade" id="fm"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id"><input type="hidden" name="year" value="<?= $year ?>">
  <div class="modal-header"><h5 class="modal-title" id="f_title">Hari Libur</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label">Keterangan</label><input name="name" id="f_name" class="form-control" required maxlength="120" placeholder="mis. Hari Kemerdekaan RI / Libur Semester"></div>
    <div class="col-6"><label class="form-label" id="l_date">Tanggal mulai</label><input type="date" name="date" id="f_date" class="form-control" required></div>
    <div class="col-6" id="boxEnd"><label class="form-label">Tanggal akhir <small class="text-muted">(opsional)</small></label><input type="date" name="end_date" id="f_end" class="form-control"></div>
    <div class="col-12" id="boxWk"><div class="form-check"><input class="form-check-input" type="checkbox" name="skip_weekend" id="f_wk"><label class="form-check-label" for="f_wk">Lewati Sabtu & Minggu pada rentang</label></div>
      <div class="form-text">Rentang maks. <?= HOLIDAY_MAX_RANGE ?> hari per input. Tanggal yang sudah terdaftar dilewati.</div></div>
  </div></div><div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openForm(r){r=r||{};f_id.value=r.id||'';f_name.value=r.name||'';f_date.value=r.date||'';f_end.value='';f_wk.checked=false;
  var edit=!!r.id;boxEnd.classList.toggle('d-none',edit);boxWk.classList.toggle('d-none',edit);l_date.textContent=edit?'Tanggal':'Tanggal mulai';f_title.textContent=edit?'Ubah Hari Libur':'Tambah Hari Libur';
  new bootstrap.Modal(fm).show()}
</script>

<?php
// Backup & restore database. Khusus Super Admin (file backup memuat hash password dan token QR).
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
require_once __DIR__ . '/../../app/helpers/backup.php';
$u = require_login(['super_admin']);
$pdo = db();
@set_time_limit(300);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'create') {
            $r = backup_create($pdo);
            log_activity('backup', 'database', $r['file'], $r['rows'] . ' baris');
            flash('ok', 'Backup dibuat: ' . $r['file'] . ' (' . number_format($r['rows'], 0, ',', '.') . ' baris).');
        } elseif ($act === 'delete') {
            $n = (string)($_POST['file'] ?? ''); if (!backup_name_ok($n)) throw new RuntimeException('Nama file tidak valid.');
            @unlink(backup_dir() . '/' . $n);
            log_activity('backup_delete', 'database', $n);
            flash('ok', 'File backup dihapus.');
        } elseif ($act === 'restore_stored' || $act === 'restore_upload') {
            // Konfirmasi berlapis: password pengguna + ketik RESTORE
            if (trim($_POST['confirm_text'] ?? '') !== 'RESTORE') throw new RuntimeException('Ketik RESTORE (huruf besar) untuk melanjutkan.');
            if (!rate_limit('restore:' . $u['id'], 5, 600)) throw new RuntimeException('Terlalu banyak percobaan. Coba lagi beberapa menit lagi.');
            $h = $pdo->prepare('SELECT password_hash FROM users WHERE id=?'); $h->execute([$u['id']]);
            if (!password_verify((string)($_POST['password'] ?? ''), (string)$h->fetchColumn())) throw new RuntimeException('Password salah.');

            if ($act === 'restore_stored') {
                $n = (string)($_POST['file'] ?? ''); if (!backup_name_ok($n)) throw new RuntimeException('Nama file tidak valid.');
                $path = backup_dir() . '/' . $n; if (!is_file($path)) throw new RuntimeException('File backup tidak ditemukan.');
                $src = $n;
            } else {
                $f = $_FILES['sqlfile'] ?? ['error' => UPLOAD_ERR_NO_FILE];
                if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('File melebihi batas upload server (upload_max_filesize ' . ini_get('upload_max_filesize') . '). Salin file ke folder storage/backups lewat FTP lalu pulihkan dari daftar.');
                if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pilih file backup (.sql) terlebih dahulu.');
                if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'sql') throw new RuntimeException('File harus berekstensi .sql.');
                $path = $f['tmp_name']; $src = 'unggahan ' . basename($f['name']);
            }
            backup_validate_file($path);   // tolak file rusak/asing SEBELUM menyentuh database

            $safety = backup_create($pdo, 'pre-restore');   // jaring pengaman
            try {
                $cnt = backup_restore($pdo, $path);
            } catch (Throwable $e) {
                try { backup_restore($pdo, backup_dir() . '/' . $safety['file']); $rb = ' Database dikembalikan ke kondisi sebelum restore.'; }
                catch (Throwable $e2) { $rb = ' PERINGATAN: pemulihan otomatis gagal; pulihkan manual dari ' . $safety['file'] . '.'; }
                app_log_error($e);
                throw new RuntimeException('Restore gagal.' . $rb);
            }
            // Data pengguna berubah total: akhiri sesi dan minta login ulang.
            try { log_activity('restore', 'database', null, "$src ($cnt pernyataan); pengaman: " . $safety['file']); } catch (Throwable $ignored) {}
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['flash_info'] = "Restore berhasil dari $src. Silakan login kembali. Backup pengaman: " . $safety['file'];
            redirect('auth/login.php');
        }
    } catch (Throwable $e) {
        flash('err', $e instanceof PDOException ? 'Kesalahan database: ' . (APP_DEBUG ? $e->getMessage() : 'periksa izin pengguna DB.') : $e->getMessage());
    }
    redirect('modules/backup/index.php');
}

$files = backup_list();
$uploadMax = ini_get('upload_max_filesize');
render_header('Backup & Restore', 'backup'); ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card stat-card h-100"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div><h5 class="mb-0">Backup Database</h5><small class="text-muted">File .sql berisi seluruh data (siswa, absensi, pengguna, pengaturan).</small></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><button class="btn btn-primary"><i class="bi bi-database-add"></i> Buat Backup</button></form>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>File</th><th>Waktu</th><th>Ukuran</th><th></th></tr></thead><tbody>
    <?php foreach ($files as $f): ?><tr>
      <td class="small text-break"><?= e($f['name']) ?></td><td class="small text-nowrap"><?= e(date('d/m/Y H:i', $f['time'])) ?></td><td class="small"><?= e(format_bytes((int)$f['size'])) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-primary" href="<?= url('modules/backup/download.php?file=' . urlencode($f['name'])) ?>" title="Unduh"><i class="bi bi-download"></i></a>
        <button class="btn btn-sm btn-outline-warning" title="Pulihkan" onclick='openRestore(<?= json_encode($f['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="bi bi-arrow-counterclockwise"></i></button>
        <form method="post" class="d-inline confirm-form" data-msg="Hapus file backup ini dari server?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= e($f['name']) ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
      </td></tr><?php endforeach; ?>
    <?php if (!$files): ?><tr><td colspan="4" class="text-center text-muted py-3">Belum ada backup.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="alert alert-info small mb-0"><i class="bi bi-info-circle"></i> Unduh dan simpan backup di tempat lain (flash disk/cloud), jangan hanya di server. Foto siswa, logo, dan bukti izin <b>tidak</b> ada di file .sql &mdash; unduh juga arsip file upload di bawah.</div>
  </div></div></div>
  <div class="col-lg-5 vstack gap-3">
    <div class="card stat-card"><div class="card-body">
      <h5>Pulihkan dari File</h5>
      <p class="small text-muted">Unggah file .sql hasil backup aplikasi ini (maks. <?= e($uploadMax) ?>). Sebelum memulihkan, sistem otomatis membuat backup pengaman.</p>
      <button class="btn btn-warning" onclick="openRestore(null)"><i class="bi bi-upload"></i> Pilih File & Pulihkan</button>
    </div></div>
    <div class="card stat-card"><div class="card-body">
      <h5>Arsip File Upload</h5>
      <p class="small text-muted">ZIP berisi foto siswa, logo, dan bukti izin/sakit (folder <code>uploads/</code>).</p>
      <a class="btn btn-outline-primary" href="<?= url('modules/backup/download.php?type=uploads') ?>"><i class="bi bi-file-earmark-zip"></i> Unduh ZIP Upload</a>
    </div></div>
  </div>
</div>
<div class="modal fade" id="rm"><div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" id="r_action" value="restore_upload"><input type="hidden" name="file" id="r_file">
  <div class="modal-header bg-warning-subtle"><h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Pulihkan Database</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-3">
    <div class="alert alert-danger small mb-0"><b>Semua data saat ini akan DITIMPA</b> dengan isi file backup, termasuk akun pengguna. Setelah selesai Anda akan logout dan harus login dengan akun yang ada di backup.</div>
    <div id="r_src" class="fw-semibold small"></div>
    <div id="r_upload"><label class="form-label">File backup (.sql)</label><input type="file" name="sqlfile" id="r_sql" accept=".sql" class="form-control"></div>
    <div><label class="form-label">Password Anda</label><input type="password" name="password" class="form-control" required autocomplete="current-password"></div>
    <div><label class="form-label">Ketik <code>RESTORE</code> untuk konfirmasi</label><input name="confirm_text" class="form-control" required autocomplete="off"></div>
  </div><div class="modal-footer"><button class="btn btn-warning">Pulihkan Sekarang</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openRestore(name){
  r_action.value=name?'restore_stored':'restore_upload';r_file.value=name||'';
  r_upload.classList.toggle('d-none',!!name);r_sql.required=!name;
  r_src.textContent=name?('Sumber: '+name):'';
  new bootstrap.Modal(rm).show()}
</script>

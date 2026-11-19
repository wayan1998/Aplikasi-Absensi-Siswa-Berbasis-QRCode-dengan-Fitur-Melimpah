<?php
// Pengaturan sekolah: identitas (dipakai di kop laporan & kartu), logo, keamanan sesi, batas scan.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
$u = require_login(['super_admin', 'admin']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $name = trim($_POST['school_name'] ?? ''); $npsn = trim($_POST['npsn'] ?? ''); $addr = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? ''); $principal = trim($_POST['principal'] ?? ''); $nip = trim($_POST['principal_nip'] ?? '');
        $timeoutMin = (int)($_POST['session_timeout_min'] ?? 30); $rate = (int)($_POST['scan_rate_limit'] ?? 60);
        if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Nama sekolah wajib diisi (maks 120 karakter).');
        if ($npsn !== '' && !preg_match('/^\d{8}$/', $npsn)) throw new RuntimeException('NPSN harus 8 digit angka.');
        if (mb_strlen($addr) > 255 || mb_strlen($city) > 60 || mb_strlen($principal) > 120) throw new RuntimeException('Teks terlalu panjang.');
        if ($nip !== '' && !preg_match('/^[0-9 ]{5,30}$/', $nip)) throw new RuntimeException('NIP hanya berisi angka (5-30 digit).');
        if ($timeoutMin < 5 || $timeoutMin > 480) throw new RuntimeException('Batas tidak aktif harus 5-480 menit.');
        if ($rate < 10 || $rate > 600) throw new RuntimeException('Batas scan per menit harus 10-600.');

        $oldLogo = setting('logo'); $logo = $oldLogo;
        $new = save_uploaded_photo($_FILES['logo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'school');   // JPG/PNG/WEBP, maks 2MB
        if ($new) $logo = $new; elseif (isset($_POST['remove_logo'])) $logo = '';

        $pdo->beginTransaction();
        foreach (['school_name' => $name, 'npsn' => $npsn, 'address' => $addr, 'city' => $city, 'principal' => $principal, 'principal_nip' => $nip,
                  'logo' => $logo, 'session_timeout' => (string)($timeoutMin * 60), 'scan_rate_limit' => (string)$rate] as $k => $v) set_setting($pdo, $k, $v);
        $pdo->commit();
        if ($logo !== $oldLogo) delete_upload('school', $oldLogo);
        log_activity('save', 'school_settings', null, $name);
        flash('ok', 'Pengaturan disimpan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!empty($new)) delete_upload('school', $new);
        flash('err', $e instanceof PDOException ? 'Kesalahan database.' : $e->getMessage());
    }
    redirect('modules/settings/index.php');
}

$logo = setting('logo');
render_header('Pengaturan Sekolah', 'settings'); settings_tabs('settings'); ?>
<form method="post" enctype="multipart/form-data" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-lg-8"><div class="card stat-card"><div class="card-body">
    <h5 class="mb-3">Identitas Sekolah</h5>
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label">Nama Sekolah <span class="text-danger">*</span></label><input name="school_name" class="form-control" required maxlength="120" value="<?= e(setting('school_name')) ?>"></div>
      <div class="col-md-4"><label class="form-label">NPSN</label><input name="npsn" class="form-control" inputmode="numeric" maxlength="8" pattern="\d{8}" placeholder="8 digit" value="<?= e(setting('npsn')) ?>"></div>
      <div class="col-md-8"><label class="form-label">Alamat</label><input name="address" class="form-control" maxlength="255" value="<?= e(setting('address')) ?>"></div>
      <div class="col-md-4"><label class="form-label">Kota/Kabupaten (untuk tanda tangan)</label><input name="city" class="form-control" maxlength="60" value="<?= e(setting('city', 'Mopuya')) ?>"></div>
      <div class="col-md-7"><label class="form-label">Nama Kepala Sekolah</label><input name="principal" class="form-control" maxlength="120" value="<?= e(setting('principal')) ?>"></div>
      <div class="col-md-5"><label class="form-label">NIP Kepala Sekolah</label><input name="principal_nip" class="form-control" maxlength="30" value="<?= e(setting('principal_nip')) ?>"></div>
    </div>
    <hr>
    <h5 class="mb-3">Keamanan & Scan</h5>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Logout otomatis setelah tidak aktif (menit)</label><input type="number" name="session_timeout_min" class="form-control" min="5" max="480" required value="<?= (int)round((int)setting('session_timeout', '1800') / 60) ?>"><div class="form-text">5-480 menit. Berlaku untuk semua pengguna pada permintaan berikutnya.</div></div>
      <div class="col-md-6"><label class="form-label">Batas scan per menit (per pengguna)</label><input type="number" name="scan_rate_limit" class="form-control" min="10" max="600" required value="<?= (int)setting('scan_rate_limit', '60') ?>"><div class="form-text">Mencegah scan beruntun/penyalahgunaan endpoint.</div></div>
    </div>
  </div></div></div>
  <div class="col-lg-4"><div class="card stat-card"><div class="card-body">
    <h5 class="mb-3">Logo Sekolah</h5>
    <div class="text-center mb-3"><?php if ($logo): ?><img src="<?= url('uploads/school/' . e($logo)) ?>" alt="Logo" class="img-fluid" style="max-height:120px"><?php else: ?><div class="brand-badge"><i class="bi bi-mortarboard-fill"></i></div><div class="small text-muted mt-2">Belum ada logo</div><?php endif; ?></div>
    <input type="file" name="logo" class="form-control" accept="image/jpeg,image/png,image/webp">
    <div class="form-text">JPG/PNG/WEBP, maks 2 MB. Dipakai di kop laporan dan kartu QR. Disarankan persegi, latar transparan (PNG).</div>
    <?php if ($logo): ?><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_logo" id="rl"><label class="form-check-label" for="rl">Hapus logo</label></div><?php endif; ?>
  </div></div></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2"></i> Simpan Pengaturan</button></div>
</form>
<?php render_footer(); ?>

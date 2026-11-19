<?php
// Profil pengguna & ganti password (semua peran).
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
$u = require_login();
$pdo = db();
$me = $pdo->prepare('SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?'); $me->execute([$u['id']]); $me = $me->fetch();
if (!$me) { $_SESSION = []; redirect('auth/login.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $anchor = '';
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'profile') {
            $name = $me['role'] === 'ketua_kelas' ? $me['full_name'] : trim($_POST['full_name'] ?? '');   // nama ketua kelas mengikuti data siswa
            $email = trim($_POST['email'] ?? '');
            if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Nama wajib diisi (maks 120 karakter).');
            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120)) throw new RuntimeException('Format email tidak valid.');
            $photo = save_uploaded_photo($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'users');
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET full_name=?, email=?, photo=COALESCE(?, photo) WHERE id=?')->execute([$name, $email !== '' ? $email : null, $photo, $u['id']]);
            if ($me['role'] === 'guru') $pdo->prepare('UPDATE teachers SET full_name=? WHERE user_id=?')->execute([$name, $u['id']]);
            $pdo->commit();
            if ($photo) delete_upload('users', $me['photo']);
            $_SESSION['user']['name'] = $name; if ($photo) $_SESSION['user']['photo'] = $photo;
            log_activity('profile', 'users', $u['id']);
            flash('ok', 'Profil diperbarui.');
        } elseif ($act === 'password') {
            $anchor = '#password';
            $old = (string)($_POST['old_password'] ?? ''); $new = (string)($_POST['new_password'] ?? ''); $new2 = (string)($_POST['new_password2'] ?? '');
            if (!rate_limit('pw:' . $u['id'], 5, 600)) throw new RuntimeException('Terlalu banyak percobaan. Coba lagi beberapa menit lagi.');
            if (!password_verify($old, $me['password_hash'])) throw new RuntimeException('Password saat ini salah.');
            if (strlen($new) < 8) throw new RuntimeException('Password baru minimal 8 karakter.');
            if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) throw new RuntimeException('Password baru harus memuat huruf dan angka.');
            if (strcasecmp($new, 'password') === 0 || strcasecmp($new, $me['username']) === 0) throw new RuntimeException('Password terlalu mudah ditebak.');
            if ($new !== $new2) throw new RuntimeException('Konfirmasi password baru tidak sama.');
            if (password_verify($new, $me['password_hash'])) throw new RuntimeException('Password baru harus berbeda dari password saat ini.');
            $pdo->prepare('UPDATE users SET password_hash=?, failed_logins=0, locked_until=NULL WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            unset($_SESSION['weak_pw']);
            log_activity('change_password', 'users', $u['id']);
            flash('ok', 'Password berhasil diganti.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!empty($photo)) delete_upload('users', $photo);
        flash('err', $e instanceof PDOException ? 'Kesalahan database.' : $e->getMessage());
    }
    redirect('modules/profile/index.php' . $anchor);
}

render_header('Profil Saya', 'profile'); ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card stat-card"><div class="card-body">
    <h5 class="mb-3">Data Akun</h5>
    <form method="post" enctype="multipart/form-data" class="row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <div class="col-12 d-flex align-items-center gap-3">
        <?php if ($me['photo']): ?><img src="<?= url('uploads/users/' . e($me['photo'])) ?>" width="72" height="72" class="rounded-circle object-fit-cover" alt="">
        <?php else: ?><span class="stat-icon" style="width:72px;height:72px;font-size:2rem;border-radius:50%"><?= e(mb_strtoupper(mb_substr($me['full_name'], 0, 1))) ?></span><?php endif; ?>
        <div class="flex-grow-1"><label class="form-label small mb-1">Foto profil (JPG/PNG/WEBP, maks 2 MB)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm"></div>
      </div>
      <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" value="<?= e($me['username']) ?>" disabled></div>
      <div class="col-md-6"><label class="form-label">Peran</label><input class="form-control" value="<?= e(role_label($me['role'])) ?>" disabled></div>
      <div class="col-md-6"><label class="form-label">Nama Lengkap</label><input name="full_name" class="form-control" required maxlength="120" value="<?= e($me['full_name']) ?>" <?= $me['role'] === 'ketua_kelas' ? 'readonly' : '' ?>>
        <?php if ($me['role'] === 'ketua_kelas'): ?><div class="form-text">Nama mengikuti data siswa; minta admin untuk mengubahnya.</div><?php endif; ?></div>
      <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" maxlength="120" value="<?= e($me['email']) ?>"></div>
      <div class="col-12 small text-muted">Login terakhir: <?= $me['last_login_at'] ? e(date('d/m/Y H:i', strtotime($me['last_login_at']))) : '-' ?></div>
      <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2"></i> Simpan Profil</button></div>
    </form>
  </div></div></div>
  <div class="col-lg-5" id="password"><div class="card stat-card"><div class="card-body">
    <h5 class="mb-3">Ganti Password</h5>
    <?php if (!empty($_SESSION['weak_pw'])): ?><div class="alert alert-warning py-2 small">Anda masih memakai password bawaan/lemah. Segera ganti.</div><?php endif; ?>
    <form method="post" class="vstack gap-3"><?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div><label class="form-label">Password saat ini</label><input type="password" name="old_password" class="form-control" required autocomplete="current-password"></div>
      <div><label class="form-label">Password baru</label><input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password"><div class="form-text">Minimal 8 karakter, memuat huruf dan angka.</div></div>
      <div><label class="form-label">Ulangi password baru</label><input type="password" name="new_password2" class="form-control" required minlength="8" autocomplete="new-password"></div>
      <button class="btn btn-warning"><i class="bi bi-key"></i> Ganti Password</button>
    </form>
  </div></div></div>
</div>
<?php render_footer(); ?>

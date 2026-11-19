<?php
require_once __DIR__ . '/../app/middleware/auth.php';
if (!empty($_SESSION['user'])) redirect(role_home($_SESSION['user']['role']));

$error = flash('error');
$info = flash('info');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (!rate_limit('login:' . client_ip(), 10, 300)) {
        $error = 'Terlalu banyak percobaan login. Coba lagi beberapa menit lagi.';
    } else {
        $st = db()->prepare('SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username=? LIMIT 1');
        $st->execute([$username]);
        $u = $st->fetch();
        if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
            $error = 'Akun dikunci sementara. Coba lagi nanti.';
        } elseif ($u && $u['is_active'] && password_verify($password, $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['full_name'], 'role' => $u['role'], 'photo' => $u['photo']];
            // password bawaan/lemah -> tampilkan peringatan sampai diganti (Profil)
            $_SESSION['weak_pw'] = ($password === 'password' || strlen($password) < 8);
            db()->prepare('UPDATE users SET failed_logins=0, locked_until=NULL, last_login_at=NOW() WHERE id=?')->execute([$u['id']]);
            log_activity('login', 'users', $u['id']);
            redirect(role_home($u['role']));
        } else {
            if ($u) {
                $f = $u['failed_logins'] + 1;
                db()->prepare('UPDATE users SET failed_logins=?, locked_until=IF(?>=5, NOW() + INTERVAL 10 MINUTE, NULL) WHERE id=?')->execute([$f, $f, $u['id']]);
            }
            $error = 'Username atau password salah.';
        }
    }
}
?><!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - <?= e(setting('school_name', 'SMK NEGERI 1 MOPUYA')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="<?= url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="login-bg">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
  <div class="card login-card shadow-lg border-0">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-4">
        <div class="brand-badge mb-3"><i class="bi bi-qr-code-scan"></i></div>
        <h4 class="fw-bold mb-0"><?= e(setting('school_name', 'SMK NEGERI 1 MOPUYA')) ?></h4>
        <small class="text-muted">Sistem Absensi Digital QR Code</small>
      </div>
      <?php if (!empty($info)): ?><div class="alert alert-success py-2"><?= e($info) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Username</label>
          <div class="input-group"><span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" name="username" class="form-control" required autofocus></div>
        </div>
        <div class="mb-4">
          <label class="form-label">Password</label>
          <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" name="password" class="form-control" required></div>
        </div>
        <button class="btn btn-primary w-100 py-2 fw-semibold">Masuk</button>
      </form>
    </div>
  </div>
</div>
</body></html>

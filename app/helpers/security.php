<?php
// Batas tidak aktif (detik) dari Pengaturan; jatuh ke konstanta bila DB tidak terbaca. Dibatasi 5 menit - 8 jam.
function session_timeout_seconds(): int {
    try { $v = (int)setting('session_timeout', (string)SESSION_TIMEOUT); } catch (Throwable $e) { $v = SESSION_TIMEOUT; }
    return max(300, min(28800, $v));
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_name('ABSENSI_SMKN1');
    session_start();
    // logout otomatis jika tidak aktif
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > session_timeout_seconds()) {
        session_unset(); session_destroy();
        session_start();
        $_SESSION['flash_error'] = 'Sesi berakhir karena tidak aktif. Silakan login kembali.';
    }
    $_SESSION['last_activity'] = time();
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_verify(?string $token = null): bool {
    $token = $token ?? ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token);
}
function csrf_require(): void {
    if (!csrf_verify()) render_error_page(419);
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

// Rate limit berbasis DB: maksimal $max hit per $seconds untuk $bucket
function rate_limit(string $bucket, int $max, int $seconds): bool {
    $pdo = db();
    $pdo->prepare('DELETE FROM rate_limits WHERE hit_at < (NOW() - INTERVAL 1 DAY)')->execute();
    $st = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket=? AND hit_at > (NOW() - INTERVAL ? SECOND)');
    $st->execute([$bucket, $seconds]);
    if ((int)$st->fetchColumn() >= $max) return false;
    $pdo->prepare('INSERT INTO rate_limits (bucket) VALUES (?)')->execute([$bucket]);
    return true;
}

function log_activity(string $action, ?string $entity = null, $entityId = null, ?string $detail = null): void {
    try {
        db()->prepare('INSERT INTO activity_logs (user_id,action,entity,entity_id,detail,ip_address) VALUES (?,?,?,?,?,?)')
            ->execute([$_SESSION['user']['id'] ?? null, $action, $entity, $entityId, $detail, client_ip()]);
    } catch (Throwable $e) {}
}

// Validasi upload foto: hanya JPG/PNG/WEBP, maks 2MB, nama acak
function save_uploaded_photo(array $file, string $subdir): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) throw new RuntimeException('Upload gagal atau melebihi 2MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || !getimagesize($file['tmp_name'])) throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dir = APP_ROOT . '/uploads/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) throw new RuntimeException('Gagal menyimpan file.');
    return $name;
}

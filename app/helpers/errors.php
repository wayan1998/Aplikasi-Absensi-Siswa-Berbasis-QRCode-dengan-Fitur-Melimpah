<?php
// Penanganan error terpusat: halaman 403/404/419/500 yang rapi + pencatatan ke storage/logs/error.log.
// Tidak boleh bergantung pada database agar tetap tampil saat DB bermasalah.

function app_log_error(Throwable $e): void {
    try {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $line = sprintf("[%s] %s: %s in %s:%d | %s %s | ip=%s\n", date('Y-m-d H:i:s'), get_class($e), $e->getMessage(),
            str_replace(APP_ROOT, '', $e->getFile()), $e->getLine(), $_SERVER['REQUEST_METHOD'] ?? '-', $_SERVER['REQUEST_URI'] ?? '-', $_SERVER['REMOTE_ADDR'] ?? '-');
        @file_put_contents($dir . '/error.log', $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) {}
}

function wants_json(): bool {
    foreach (headers_list() as $h) if (stripos($h, 'content-type: application/json') === 0) return true;
    $a = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($a, 'application/json') && !str_contains($a, 'text/html');
}

function render_error_page(int $code, ?string $detail = null): never {
    $info = [
        403 => ['Akses Ditolak', 'Anda tidak memiliki izin untuk membuka halaman ini.', 'bi-shield-lock'],
        404 => ['Halaman Tidak Ditemukan', 'Alamat yang Anda tuju tidak ada atau sudah dipindahkan.', 'bi-compass'],
        419 => ['Sesi Formulir Kedaluwarsa', 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.', 'bi-hourglass-split'],
        500 => ['Terjadi Kesalahan Server', 'Maaf, terjadi kesalahan pada sistem. Kesalahan ini sudah dicatat. Silakan coba lagi nanti.', 'bi-exclamation-octagon'],
    ];
    [$title, $msg, $icon] = $info[$code] ?? $info[500];
    if (!headers_sent()) { http_response_code(isset($info[$code]) ? $code : 500); header('Content-Type: text/html; charset=utf-8'); }
    if (wants_json()) { echo json_encode(['level' => 'danger', 'message' => $title . '. ' . ($detail ?? $msg)], JSON_UNESCAPED_UNICODE); exit; }
    $school = 'SMK NEGERI 1 MOPUYA';
    try { $school = setting('school_name', $school); } catch (Throwable $ignored) {}   // DB mungkin sedang bermasalah
    ?><!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= (int)$code ?> - <?= e($title) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/app.css')) ?>" rel="stylesheet">
</head><body class="login-bg">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
  <div class="card login-card shadow-lg border-0 text-center">
    <div class="card-body p-4 p-md-5">
      <div class="brand-badge mb-3"><i class="bi <?= $icon ?>"></i></div>
      <div class="display-4 fw-bold text-primary"><?= (int)$code ?></div>
      <h5 class="fw-bold"><?= e($title) ?></h5>
      <p class="text-muted"><?= e($detail ?? $msg) ?></p>
      <div class="d-grid gap-2 mt-4">
        <a class="btn btn-primary" href="<?= e(url('index.php')) ?>"><i class="bi bi-house"></i> Kembali ke Beranda</a>
        <button class="btn btn-outline-secondary" onclick="history.length>1?history.back():location.href='<?= e(url('index.php')) ?>'">Halaman Sebelumnya</button>
      </div>
      <div class="small text-muted mt-4"><?= e($school) ?></div>
    </div>
  </div>
</div>
</body></html>
<?php exit;
}

function register_error_handlers(): void {
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    ini_set('log_errors', '1');
    set_exception_handler(function (Throwable $e) {
        app_log_error($e);
        if (APP_DEBUG) { http_response_code(500); echo '<pre>' . e((string)$e) . '</pre>'; return; }
        render_error_page(500);
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            app_log_error(new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
            if (!APP_DEBUG && !headers_sent()) render_error_page(500);
        }
    });
}

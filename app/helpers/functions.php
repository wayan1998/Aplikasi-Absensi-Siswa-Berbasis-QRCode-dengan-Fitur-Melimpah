<?php
function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query('SELECT setting_key, setting_value FROM school_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache[$key] ?? $default;
}
function role_home(string $role): string {
    return match ($role) {
        'super_admin', 'admin' => 'admin/index.php',
        'guru' => 'teacher/index.php',
        'ketua_kelas' => 'ketua_kelas/index.php',
        default => 'auth/login.php',
    };
}
function flash(string $type, ?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION["flash_$type"] = $msg; return null; }
    $m = $_SESSION["flash_$type"] ?? null; unset($_SESSION["flash_$type"]); return $m;
}

// Simpan/ubah satu pengaturan (upsert) pada school_settings.
function set_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO school_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
}
// Tanggal format Indonesia: "Senin, 17 Agustus 2026" (atau tanpa nama hari).
function tgl_id(string $date, bool $withDay = false): string {
    $ts = strtotime($date); if (!$ts) return $date;
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $hari = [1=>'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];
    return ($withDay ? $hari[(int)date('N', $ts)] . ', ' : '') . (int)date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
function role_label(string $role): string {
    return ['super_admin' => 'Super Admin', 'admin' => 'Admin Sekolah', 'guru' => 'Guru', 'ketua_kelas' => 'Ketua Kelas'][$role] ?? $role;
}
// Hapus file upload dengan aman (hanya nama acak hasil sistem, tanpa path).
function delete_upload(string $subdir, ?string $name): void {
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $name) && in_array($subdir, ['school', 'students', 'users', 'evidence'], true)) {
        @unlink(APP_ROOT . "/uploads/$subdir/$name");
    }
}

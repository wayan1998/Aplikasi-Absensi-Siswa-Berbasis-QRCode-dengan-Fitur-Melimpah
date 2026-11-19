<?php
// Backup & restore database murni PHP (tanpa mysqldump), cocok untuk hosting bersama.
// Format file: SATU pernyataan SQL per baris. Restore hanya menerima file buatan aplikasi ini
// dan memvalidasi SELURUH isi file sebelum menjalankan satu pun perintah.

const BACKUP_MAGIC = '-- ABSENSI-QR-BACKUP v1';
const BACKUP_END = '-- END-OF-BACKUP';
const BACKUP_NO_DATA = ['rate_limits'];   // hanya struktur, datanya sementara

// Urutan tabel aplikasi (daftar putih untuk restore). Tambahkan di sini bila ada tabel baru.
function backup_tables(): array {
    return ['roles', 'academic_years', 'semesters', 'departments', 'classes', 'users', 'teachers', 'students', 'student_qr_codes',
        'class_assignments', 'attendance_sessions', 'attendance', 'attendance_permissions', 'attendance_corrections',
        'holidays', 'school_settings', 'notifications', 'activity_logs', 'rate_limits'];
}
function backup_dir(): string {
    $d = APP_ROOT . '/storage/backups';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n");
    return $d;
}
function backup_name_ok(string $n): bool { return (bool)preg_match('/^backup_\d{8}_\d{6}(_[a-z-]{1,20})?\.sql$/', $n); }
function backup_list(): array {
    $out = [];
    foreach (glob(backup_dir() . '/backup_*.sql') ?: [] as $p) {
        $n = basename($p); if (!backup_name_ok($n)) continue;
        $out[] = ['name' => $n, 'size' => filesize($p), 'time' => filemtime($p)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
    return $out;
}

function backup_sql_value($v, PDO $pdo): string {
    if ($v === null) return 'NULL';
    if (is_int($v)) return (string)$v;
    if (is_float($v)) return rtrim(rtrim(sprintf('%.14F', $v), '0'), '.');
    if (is_bool($v)) return $v ? '1' : '0';
    // jaga-jaga: satu pernyataan harus tetap satu baris
    return str_replace(["\r", "\n"], ['\\r', '\\n'], $pdo->quote((string)$v));
}

// Tulis INSERT (batch) untuk satu tabel ke handle file; kembalikan jumlah baris.
function backup_write_table_data(PDO $pdo, string $table, $fh, int $batchRows = 200, int $batchBytes = 400000): int {
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($isMysql) $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);   // hemat memori untuk tabel besar
    $count = 0; $buf = []; $bytes = 0; $cols = null;
    $flush = function () use (&$buf, &$bytes, &$cols, $fh, $table) {
        if (!$buf) return;
        fwrite($fh, "INSERT INTO `$table` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES ' . implode(',', $buf) . ";\n");
        $buf = []; $bytes = 0;
    };
    try {
        $st = $pdo->query("SELECT * FROM `$table`");
        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $cols ??= array_keys($row);
            $t = '(' . implode(',', array_map(fn($v) => backup_sql_value($v, $pdo), array_values($row))) . ')';
            $buf[] = $t; $bytes += strlen($t); $count++;
            if (count($buf) >= $batchRows || $bytes >= $batchBytes) $flush();
        }
        $st->closeCursor();
    } finally {
        if ($isMysql) $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    $flush();
    return $count;
}

// Buat file backup lengkap. Kembalikan ['file'=>..., 'tables'=>n, 'rows'=>n].
function backup_create(PDO $pdo, string $label = ''): array {
    $label = preg_replace('/[^a-z-]/', '', strtolower($label));
    $name = 'backup_' . date('Ymd_His') . ($label !== '' ? "_$label" : '') . '.sql';
    $path = backup_dir() . '/' . $name; $tmp = $path . '.tmp';
    $fh = fopen($tmp, 'wb'); if (!$fh) throw new RuntimeException('Tidak dapat menulis ke folder storage/backups. Periksa izin folder.');
    $oldMode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $rows = 0;
    try {
        $pdo->exec("SET SESSION sql_mode=''");   // pastikan escape backslash standar saat quote()
        fwrite($fh, BACKUP_MAGIC . "\n-- Dibuat: " . date('Y-m-d H:i:s') . "\n-- Database: " . DB_NAME . "\n-- Jangan ubah isi file ini secara manual.\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach (backup_tables() as $t) {
            $ddl = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1] ?? null;
            if (!$ddl) throw new RuntimeException("Tabel $t tidak ditemukan. Pastikan database.sql sudah diimpor.");
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n" . preg_replace('/\s*\R\s*/', ' ', $ddl) . ";\n");
            if (!in_array($t, BACKUP_NO_DATA, true)) $rows += backup_write_table_data($pdo, $t, $fh);
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n" . BACKUP_END . "\n");
        fclose($fh); $fh = null;
        if (!rename($tmp, $path)) throw new RuntimeException('Gagal menyimpan file backup.');
    } catch (Throwable $e) {
        if (is_resource($fh)) fclose($fh);
        @unlink($tmp); throw $e;
    } finally {
        try { $pdo->exec('SET SESSION sql_mode=' . $pdo->quote((string)$oldMode)); } catch (Throwable $ignored) {}
    }
    return ['file' => $name, 'tables' => count(backup_tables()), 'rows' => $rows];
}

// True bila $stmt adalah TEPAT satu pernyataan: satu ';' di akhir, tanpa ';' atau komentar di luar tanda kutip.
function sql_is_single_statement(string $stmt): bool {
    $n = strlen($stmt); if ($n < 2 || $stmt[$n - 1] !== ';') return false;
    $inStr = false; $inId = false;
    for ($i = 0; $i < $n - 1; $i++) {
        $c = $stmt[$i];
        if ($inStr) { if ($c === '\\') $i++; elseif ($c === "'") { if ($stmt[$i + 1] === "'") $i++; else $inStr = false; } continue; }
        if ($inId) { if ($c === '`') $inId = false; continue; }
        if ($c === "'") $inStr = true;
        elseif ($c === '`') $inId = true;
        elseif ($c === ';' || $c === '#') return false;
        elseif ($c === '/' && $stmt[$i + 1] === '*') return false;
        elseif ($c === '-' && $stmt[$i + 1] === '-') return false;
    }
    return !$inStr && !$inId;
}

// Validasi satu baris; kembalikan pesan error atau null bila aman.
function backup_validate_line(string $line, array $tables): ?string {
    if (preg_match('/^SET NAMES utf8mb4;$/', $line) || preg_match('/^SET FOREIGN_KEY_CHECKS=[01];$/', $line)) return null;
    if (preg_match('/^(?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([a-z_]+)`(?: |;)/', $line, $m)) {
        if (!in_array($m[1], $tables, true)) return 'tabel tidak dikenal: ' . $m[1];
        if (str_starts_with($line, 'CREATE TABLE') && !str_starts_with($line, "CREATE TABLE `{$m[1]}` (")) return 'format CREATE TABLE tidak valid';
        if (str_starts_with($line, 'INSERT INTO') && !str_starts_with($line, "INSERT INTO `{$m[1]}` (")) return 'format INSERT tidak valid';
        if (str_starts_with($line, 'DROP') && $line !== "DROP TABLE IF EXISTS `{$m[1]}`;") return 'format DROP tidak valid';
        return sql_is_single_statement($line) ? null : 'pernyataan SQL tidak valid (lebih dari satu perintah)';
    }
    return 'perintah tidak diizinkan';
}

// Validasi seluruh file tanpa menjalankan apa pun. Kembalikan jumlah pernyataan; lempar RuntimeException jika tidak valid.
function backup_validate_file(string $path, ?array $tables = null): int {
    $tables ??= backup_tables();
    $fh = @fopen($path, 'rb'); if (!$fh) throw new RuntimeException('File backup tidak dapat dibaca.');
    try {
        if (rtrim((string)fgets($fh)) !== BACKUP_MAGIC) throw new RuntimeException('Bukan file backup dari aplikasi ini (header tidak cocok).');
        $n = 0; $ln = 1; $ended = false; $seen = [];
        while (($raw = fgets($fh)) !== false) {
            $ln++; $line = rtrim($raw, "\r\n");
            if ($ended) { if ($line === '') continue; throw new RuntimeException("Baris $ln: ada isi setelah penutup file."); }
            if ($line === '' || (str_starts_with($line, '-- ') && $line !== BACKUP_END)) continue;
            if ($line === BACKUP_END) { $ended = true; continue; }
            if (($err = backup_validate_line($line, $tables)) !== null) throw new RuntimeException("Baris $ln: $err.");
            if (preg_match('/^CREATE TABLE `([a-z_]+)`/', $line, $m)) $seen[$m[1]] = true;
            $n++;
        }
        if (!$ended) throw new RuntimeException('File backup tidak lengkap (terpotong). Unggah ulang file yang utuh.');
        $miss = array_diff($tables, array_keys($seen));
        if ($miss) throw new RuntimeException('File backup tidak memuat tabel: ' . implode(', ', $miss) . '.');
        return $n;
    } finally { fclose($fh); }
}

// Jalankan file backup yang sudah divalidasi. DDL MySQL tidak bisa di-rollback; karena itu pemanggil
// membuat backup pengaman lebih dulu dan memulihkannya bila proses ini gagal.
function backup_restore(PDO $pdo, string $path): int {
    $total = backup_validate_file($path);
    $fh = fopen($path, 'rb'); fgets($fh); $done = 0;
    try {
        $pdo->exec("SET SESSION sql_mode=''");
        while (($raw = fgets($fh)) !== false) {
            $line = rtrim($raw, "\r\n");
            if ($line === '' || str_starts_with($line, '--')) continue;
            $pdo->exec($line); $done++;
        }
    } finally {
        fclose($fh);
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
    }
    return $done;
}

function format_bytes(int $b): string {
    foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) { if ($b < 1024 || $i === 3) return ($i ? number_format($b, 1, ',', '.') : $b) . ' ' . $u; $b /= 1024; }
    return '';
}

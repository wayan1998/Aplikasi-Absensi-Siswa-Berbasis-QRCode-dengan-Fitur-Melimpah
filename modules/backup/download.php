<?php
// Unduh file backup (.sql) atau arsip folder uploads (.zip). Khusus Super Admin.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/backup.php';
$u = require_login(['super_admin']);

if (($_GET['type'] ?? '') === 'uploads') {
    if (!class_exists('ZipArchive')) { flash('err', 'Ekstensi PHP zip tidak aktif di server ini.'); redirect('modules/backup/index.php'); }
    $tmp = tempnam(sys_get_temp_dir(), 'upl'); $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { flash('err', 'Gagal membuat ZIP.'); redirect('modules/backup/index.php'); }
    $root = realpath(APP_ROOT . '/uploads'); $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile() || $f->getFilename() === '.htaccess' || $f->getFilename() === '.gitkeep') continue;
        $zip->addFile($f->getPathname(), 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1))); $n++;
    }
    if ($n === 0) $zip->addFromString('uploads/README.txt', 'Belum ada file upload.');
    $zip->close();
    log_activity('backup_uploads', 'uploads', null, "$n file");
    $name = 'uploads_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . $name . '"'); header('Content-Length: ' . filesize($tmp)); header('Cache-Control: no-store');
    readfile($tmp); @unlink($tmp); exit;
}

$n = (string)($_GET['file'] ?? '');
$path = backup_dir() . '/' . $n;
if (!backup_name_ok($n) || !is_file($path)) render_error_page(404);
log_activity('backup_download', 'database', $n);
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $n . '"');
header('Content-Length: ' . filesize($path)); header('Cache-Control: no-store');
readfile($path); exit;

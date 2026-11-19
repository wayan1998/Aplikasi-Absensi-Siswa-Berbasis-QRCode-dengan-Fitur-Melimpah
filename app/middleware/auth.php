<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/errors.php';
register_error_handlers();
start_secure_session();

function require_login(array $roles = []): array {
    if (empty($_SESSION['user'])) redirect('auth/login.php');
    $u = $_SESSION['user'];
    if ($roles && !in_array($u['role'], $roles, true)) {
        render_error_page(403);
    }
    return $u;
}

// Daftar kelas yang boleh diakses user (null = semua kelas)
function allowed_class_ids(array $u): ?array {
    if (in_array($u['role'], ['super_admin', 'admin'], true)) return null;
    $st = db()->prepare('SELECT class_id FROM class_assignments WHERE user_id=?');
    $st->execute([$u['id']]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

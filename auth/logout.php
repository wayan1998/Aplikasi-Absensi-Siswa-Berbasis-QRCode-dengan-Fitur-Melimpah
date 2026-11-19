<?php
require_once __DIR__ . '/../app/middleware/auth.php';
log_activity('logout', 'users', $_SESSION['user']['id'] ?? null);
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect('auth/login.php');

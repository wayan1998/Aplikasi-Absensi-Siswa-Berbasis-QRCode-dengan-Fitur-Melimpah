<?php
require_once __DIR__ . '/app/middleware/auth.php';
redirect(empty($_SESSION['user']) ? 'auth/login.php' : role_home($_SESSION['user']['role']));

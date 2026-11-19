<?php
require_once __DIR__ . '/../app/middleware/auth.php';
require_once __DIR__ . '/../app/views/layout.php';
require_once __DIR__ . '/../modules/dashboard/class_dashboard.php';
$u = require_login(['ketua_kelas']);
render_class_dashboard($u);

<?php
// Dipakai sebagai ErrorDocument Apache (lihat .htaccess) dan bisa dibuka langsung.
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/security.php';
require_once __DIR__ . '/../app/helpers/functions.php';
require_once __DIR__ . '/../app/helpers/errors.php';
render_error_page(403);

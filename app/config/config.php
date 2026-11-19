<?php
// Konfigurasi utama. Sesuaikan dengan server Anda.
define('DB_HOST', 'localhost');
define('DB_NAME', 'absensi_qr_smkn1mopuya');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', '/absensi-smkn1mopuya'); // tanpa slash di akhir; kosongkan jika di root domain
define('APP_ROOT', dirname(__DIR__, 2));
define('SESSION_TIMEOUT', 1800); // detik; logout otomatis jika tidak aktif
define('APP_DEBUG', false);
date_default_timezone_set('Asia/Makassar');

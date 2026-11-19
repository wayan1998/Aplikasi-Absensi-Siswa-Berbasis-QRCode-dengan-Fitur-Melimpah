<?php
// Endpoint AJAX scan QR. Semua validasi dilakukan di server.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/attendance.php';
header('Content-Type: application/json; charset=utf-8');

function out(string $level, string $msg, array $extra = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(['level' => $level, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE); exit;
}
if (empty($_SESSION['user'])) out('danger', 'Sesi login berakhir. Muat ulang halaman.', [], 401);
$u = $_SESSION['user'];
if (!in_array($u['role'], ['super_admin', 'admin', 'guru', 'ketua_kelas'], true)) out('danger', 'Tidak memiliki hak scan.', [], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out('danger', 'Metode tidak valid.', [], 405);
if (!csrf_verify()) out('danger', 'Token keamanan tidak valid. Muat ulang halaman.', [], 419);

$pdo = db();
$limit = (int)setting('scan_rate_limit', '60');
if (!rate_limit('scan:' . $u['id'], $limit, 60)) out('warning', 'Terlalu banyak scan. Tunggu sebentar.', [], 429);

$token = trim((string)($_POST['token'] ?? ''));
$sessionId = (int)($_POST['session_id'] ?? 0);
$classId = (int)($_POST['class_id'] ?? 0);
if (!$sessionId || !$classId) out('danger', 'Pilih sesi dan kelas terlebih dahulu.');

// hak akses kelas
$allowed = allowed_class_ids($u);
if ($allowed !== null && !in_array($classId, $allowed, true)) out('danger', 'Anda tidak berhak melakukan scan untuk kelas ini.', [], 403);

// sesi: harus berlaku untuk kelas, hari ini, dan sedang dibuka
$sess = null;
foreach (sessions_for_class($pdo, $classId) as $s) if ((int)$s['id'] === $sessionId) { $sess = $s; break; }
if (!$sess) out('warning', 'Sesi absensi tidak berlaku untuk kelas/hari ini.');
if (!session_is_open($sess)) out('warning', 'Sesi absensi sudah ditutup atau belum dibuka (' . substr($sess['start_time'], 0, 5) . '-' . substr($sess['end_time'], 0, 5) . ').');

// token QR
if (!preg_match('/^[a-f0-9]{48}$/', $token)) out('danger', 'QR Code tidak valid.');
$st = $pdo->prepare("SELECT s.id, s.nis, s.full_name, s.photo, s.class_id, c.name AS class_name, d.code AS dept_code
    FROM student_qr_codes q JOIN students s ON s.id=q.student_id JOIN classes c ON c.id=s.class_id JOIN departments d ON d.id=c.department_id
    WHERE q.token=? AND q.is_active=1 AND s.status='aktif' LIMIT 1");
$st->execute([$token]);
$stu = $st->fetch();
if (!$stu) out('danger', 'QR Code tidak valid atau sudah tidak aktif.');
$info = ['student' => [
    'name' => $stu['full_name'], 'nis' => $stu['nis'], 'dept' => $stu['dept_code'], 'class' => $stu['class_name'],
    'photo' => $stu['photo'] ? url('uploads/students/' . $stu['photo']) : null,
]];
if ((int)$stu['class_id'] !== $classId) out('danger', 'Siswa bukan anggota kelas tersebut.', $info);

$now = date('H:i:s');
$status = $now > $sess['late_after'] ? 'TERLAMBAT' : 'HADIR';
$today = date('Y-m-d');
try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO attendance (session_id,student_id,class_id,attendance_date,status,scanned_at,scanned_by,method) VALUES (?,?,?,?,?,NOW(),?,\'qr\')')
        ->execute([$sessionId, $stu['id'], $classId, $today, $status, $u['id']]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') {   // UNIQUE (session, student, date) -> sudah absen
        $ex = $pdo->prepare('SELECT status, scanned_at FROM attendance WHERE session_id=? AND student_id=? AND attendance_date=?');
        $ex->execute([$sessionId, $stu['id'], $today]); $row = $ex->fetch() ?: [];
        out('warning', 'Siswa sudah melakukan absensi.', $info + ['status' => $row['status'] ?? null, 'time' => isset($row['scanned_at']) ? date('H:i:s', strtotime($row['scanned_at'])) : null]);
    }
    out('danger', 'Terjadi kesalahan server.', [], 500);
}
log_activity('scan', 'attendance', (string)$stu['id'], "$status sesi $sessionId");
out('success', $status === 'HADIR' ? 'Absensi berhasil.' : 'Absensi berhasil (terlambat).', $info + ['status' => $status, 'time' => $now]);

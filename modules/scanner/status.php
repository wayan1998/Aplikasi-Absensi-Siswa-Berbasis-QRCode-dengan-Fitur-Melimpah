<?php
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/attendance.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user'])) { http_response_code(401); echo '{}'; exit; }
$u = $_SESSION['user']; $pdo = db();
$classId = (int)($_GET['class_id'] ?? 0); $sessionId = (int)($_GET['session_id'] ?? 0);
$allowed = allowed_class_ids($u);
if ($allowed !== null && !in_array($classId, $allowed, true)) { http_response_code(403); echo '{}'; exit; }
$today = date('Y-m-d');
$p = $pdo->prepare("SELECT s.id, s.nis, s.full_name, a.status, a.scanned_at FROM students s
    LEFT JOIN attendance a ON a.student_id=s.id AND a.session_id=? AND a.attendance_date=?
    WHERE s.class_id=? AND s.status='aktif' ORDER BY s.full_name");
$p->execute([$sessionId, $today, $classId]);
$present = []; $absent = [];
foreach ($p as $r) {
    if ($r['status']) $present[] = ['name' => $r['full_name'], 'nis' => $r['nis'], 'status' => $r['status'], 'time' => $r['scanned_at'] ? date('H:i', strtotime($r['scanned_at'])) : '-'];
    else $absent[] = ['name' => $r['full_name'], 'nis' => $r['nis']];
}
echo json_encode(['present' => $present, 'absent' => $absent], JSON_UNESCAPED_UNICODE);

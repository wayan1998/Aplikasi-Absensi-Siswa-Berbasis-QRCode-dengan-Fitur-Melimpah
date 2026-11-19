<?php
// Daftar sesi yang berlaku untuk kelas tertentu pada hari ini (aktif, hari cocok, bukan libur).
function sessions_for_class(PDO $pdo, int $classId, ?string $date = null): array {
    $date = $date ?? date('Y-m-d');
    $h = $pdo->prepare('SELECT 1 FROM holidays WHERE holiday_date=?'); $h->execute([$date]);
    if ($h->fetchColumn()) return [];
    $dow = (string)date('N', strtotime($date));
    $st = $pdo->prepare('SELECT s.* FROM attendance_sessions s JOIN classes c ON c.id=? WHERE s.is_active=1
        AND (s.class_id IS NULL OR s.class_id=c.id) AND (s.department_id IS NULL OR s.department_id=c.department_id)
        AND FIND_IN_SET(?, s.active_days) ORDER BY s.start_time');
    $st->execute([$classId, $dow]);
    return $st->fetchAll();
}
function session_is_open(array $s, ?string $now = null): bool {
    $now = $now ?? date('H:i:s');
    return $now >= $s['start_time'] && $now <= $s['end_time'];
}
function class_stats(PDO $pdo, array $classIds, string $date): array {
    if (!$classIds) return ['total'=>0,'HADIR'=>0,'TERLAMBAT'=>0,'IZIN'=>0,'SAKIT'=>0,'ALPA'=>0,'DISPENSASI'=>0,'belum'=>0,'persen'=>0];
    $in = implode(',', array_fill(0, count($classIds), '?'));
    $t = $pdo->prepare("SELECT COUNT(*) FROM students WHERE status='aktif' AND class_id IN ($in)"); $t->execute($classIds);
    $total = (int)$t->fetchColumn();
    $r = ['total' => $total, 'HADIR'=>0,'TERLAMBAT'=>0,'IZIN'=>0,'SAKIT'=>0,'ALPA'=>0,'DISPENSASI'=>0];
    $q = $pdo->prepare("SELECT status, COUNT(DISTINCT student_id) n FROM attendance WHERE attendance_date=? AND class_id IN ($in) GROUP BY status");
    $q->execute([$date, ...$classIds]);
    foreach ($q as $row) $r[$row['status']] = (int)$row['n'];
    $any = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM attendance WHERE attendance_date=? AND class_id IN ($in)");
    $any->execute([$date, ...$classIds]);
    $r['belum'] = max(0, $total - (int)$any->fetchColumn());
    $r['persen'] = $total ? round(($r['HADIR'] + $r['TERLAMBAT']) / $total * 100, 1) : 0;
    return $r;
}

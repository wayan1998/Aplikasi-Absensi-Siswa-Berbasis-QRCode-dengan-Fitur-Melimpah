<?php
function report_filters(array $u, array $in): array {
    $from = DateTime::createFromFormat('Y-m-d', $in['from'] ?? '') ? $in['from'] : date('Y-m-01');
    $to = DateTime::createFromFormat('Y-m-d', $in['to'] ?? '') ? $in['to'] : date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
    $group = in_array($in['group'] ?? '', ['student', 'class', 'dept', 'daily', 'detail'], true) ? $in['group'] : 'student';
    $status = in_array($in['status'] ?? '', ['HADIR','TERLAMBAT','IZIN','SAKIT','ALPA','DISPENSASI'], true) ? $in['status'] : '';
    return ['from' => $from, 'to' => $to, 'group' => $group, 'status' => $status, 'dept' => (int)($in['dept'] ?? 0), 'class' => (int)($in['class'] ?? 0), 'q' => trim($in['q'] ?? '')];
}
function report_rows(PDO $pdo, array $u, array $f): array {
    $where = ['a.attendance_date BETWEEN ? AND ?']; $p = [$f['from'], $f['to']];
    $allowed = allowed_class_ids($u);
    if ($allowed !== null) { if (!$allowed) return []; $where[] = 'a.class_id IN (' . implode(',', array_map('intval', $allowed)) . ')'; }
    if ($f['dept']) { $where[] = 'c.department_id=?'; $p[] = $f['dept']; }
    if ($f['class']) { $where[] = 'a.class_id=?'; $p[] = $f['class']; }
    if ($f['q'] !== '') { $where[] = '(s.full_name LIKE ? OR s.nis LIKE ?)'; $p[] = '%' . $f['q'] . '%'; $p[] = '%' . $f['q'] . '%'; }
    $w = implode(' AND ', $where);
    $from = 'FROM attendance a JOIN students s ON s.id=a.student_id JOIN classes c ON c.id=a.class_id JOIN departments d ON d.id=c.department_id';
    if ($f['group'] === 'detail') {
        if ($f['status']) { $w .= ' AND a.status=?'; $p[] = $f['status']; }
        $st = $pdo->prepare("SELECT a.attendance_date, s.nis, s.full_name, c.name AS class_name, ss.name AS session_name, a.status, a.scanned_at, a.method, a.note $from JOIN attendance_sessions ss ON ss.id=a.session_id WHERE $w ORDER BY a.attendance_date DESC, c.name, s.full_name LIMIT 5000");
        $st->execute($p); return $st->fetchAll();
    }
    $map = [
        'student' => ['s.id', 's.full_name', "CONCAT(s.nis, ' / ', c.name)"],
        'class' => ['c.id', 'c.name', 'd.code'],
        'dept' => ['d.id', 'd.code', 'd.name'],
        'daily' => ['a.attendance_date', 'a.attendance_date', "''"],
    ][$f['group']];
    $having = $f['status'] ? 'HAVING SUM(a.status=?) > 0' : ''; if ($f['status']) $p[] = $f['status'];
    $grp = $map[0] . ', ' . $map[1] . ($f['group'] === 'daily' ? '' : ', ' . $map[2]);
    $sql = "SELECT {$map[1]} AS label, {$map[2]} AS extra,
        SUM(a.status='HADIR') h, SUM(a.status='TERLAMBAT') t, SUM(a.status='IZIN') i, SUM(a.status='SAKIT') sk, SUM(a.status='ALPA') al, SUM(a.status='DISPENSASI') dp, COUNT(*) total
        $from WHERE $w GROUP BY $grp $having";
    $st = $pdo->prepare($sql); $st->execute($p); $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['persen'] = $r['total'] ? round(($r['h'] + $r['t']) / $r['total'] * 100, 1) : 0;
    unset($r);
    // ranking kedisiplinan: persentase hadir tertinggi, lalu paling sedikit terlambat/alpa
    if ($f['group'] === 'daily') usort($rows, fn($a, $b) => strcmp($a['label'], $b['label']));
    else usort($rows, fn($a, $b) => [$b['persen'], $a['t'] + $a['al']] <=> [$a['persen'], $b['t'] + $b['al']]);
    return $rows;
}
function report_title(array $f): string {
    return ['student' => 'Rekap per Siswa', 'class' => 'Rekap per Kelas', 'dept' => 'Rekap per Jurusan', 'daily' => 'Rekap Harian', 'detail' => 'Detail Absensi'][$f['group']];
}

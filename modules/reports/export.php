<?php
// Export Excel: tabel HTML bertipe application/vnd.ms-excel (dibuka langsung oleh Excel/LibreOffice).
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/helpers/report.php';
$u = require_login(['super_admin', 'admin', 'guru']);
$pdo = db(); $f = report_filters($u, $_GET); $rows = report_rows($pdo, $u, $f);
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="laporan_absensi_' . $f['from'] . '_' . $f['to'] . '.xls"');
echo "\xEF\xBB\xBF";
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
// cegah formula injection di Excel
$txt = fn($v) => '<td style="mso-number-format:\'\\@\'">' . $esc(preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v) . '</td>';
echo '<html><head><meta charset="utf-8"></head><body><table border="1">';
echo '<tr><th colspan="11">' . $esc(setting('school_name')) . ' - ' . $esc(report_title($f)) . ' (' . $f['from'] . ' s.d. ' . $f['to'] . ')</th></tr>';
if ($f['group'] === 'detail') {
    echo '<tr><th>Tanggal</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Sesi</th><th>Status</th><th>Waktu</th><th>Metode</th><th>Catatan</th></tr>';
    foreach ($rows as $r) echo '<tr>' . $txt($r['attendance_date']) . $txt($r['nis']) . $txt($r['full_name']) . $txt($r['class_name']) . $txt($r['session_name']) . $txt($r['status']) . $txt($r['scanned_at'] ? date('H:i', strtotime($r['scanned_at'])) : '') . $txt($r['method']) . $txt($r['note']) . '</tr>';
} else {
    echo '<tr><th>No</th><th>Nama</th><th>Keterangan</th><th>Hadir</th><th>Terlambat</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Dispensasi</th><th>Total</th><th>% Hadir</th></tr>';
    foreach ($rows as $n => $r) echo '<tr><td>' . ($n + 1) . '</td>' . $txt($r['label']) . $txt($r['extra']) . "<td>{$r['h']}</td><td>{$r['t']}</td><td>{$r['i']}</td><td>{$r['sk']}</td><td>{$r['al']}</td><td>{$r['dp']}</td><td>{$r['total']}</td><td>{$r['persen']}</td></tr>";
}
echo '</table></body></html>';

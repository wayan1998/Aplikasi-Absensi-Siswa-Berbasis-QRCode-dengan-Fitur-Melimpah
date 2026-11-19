<?php
// Input manual (izin/sakit/dispensasi/alpa), koreksi absensi dengan alasan, dan tandai alpa massal.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
require_once __DIR__ . '/../../app/helpers/attendance.php';
$u = require_login(['super_admin', 'admin', 'guru']);   // ketua kelas tidak boleh mengubah absensi
$pdo = db();
$allowed = allowed_class_ids($u);
$all = $pdo->query('SELECT id, name FROM classes WHERE is_active=1 ORDER BY name')->fetchAll();
$classes = $allowed === null ? $all : array_values(array_filter($all, fn($c) => in_array((int)$c['id'], $allowed, true)));
$classId = (int)($_REQUEST['class_id'] ?? ($classes[0]['id'] ?? 0));
if (!in_array($classId, array_column($classes, 'id'), false)) $classId = (int)($classes[0]['id'] ?? 0);
$date = $_REQUEST['date'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $date) || $date > date('Y-m-d')) $date = date('Y-m-d');
$sessions = $classId ? $pdo->query('SELECT id, name FROM attendance_sessions WHERE is_active=1 ORDER BY start_time')->fetchAll() : [];
$sessionId = (int)($_REQUEST['session_id'] ?? ($sessions[0]['id'] ?? 0));
$STATUS = ['HADIR', 'TERLAMBAT', 'IZIN', 'SAKIT', 'ALPA', 'DISPENSASI'];

function save_evidence(array $f): ?string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) throw new RuntimeException('Bukti gagal diunggah atau melebihi 2MB.');
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])] ?? null;
    if (!$ext) throw new RuntimeException('Bukti harus berupa JPG, PNG, WEBP, atau PDF.');
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], APP_ROOT . '/uploads/evidence/' . $name)) throw new RuntimeException('Gagal menyimpan bukti.');
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        if (!$classId || !$sessionId) throw new RuntimeException('Pilih kelas dan sesi.');
        if ($allowed !== null && !in_array($classId, $allowed, true)) throw new RuntimeException('Tidak berhak untuk kelas ini.');
        $act = $_POST['action'] ?? '';
        if ($act === 'set') {
            $sid = (int)$_POST['student_id']; $new = $_POST['status'] ?? ''; $reason = trim($_POST['reason'] ?? '');
            if (!in_array($new, $STATUS, true)) throw new RuntimeException('Status tidak valid.');
            if ($reason === '') throw new RuntimeException('Alasan wajib diisi.');
            $chk = $pdo->prepare("SELECT id FROM students WHERE id=? AND class_id=? AND status='aktif'"); $chk->execute([$sid, $classId]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Siswa bukan anggota kelas ini.');
            $file = in_array($new, ['IZIN', 'SAKIT', 'DISPENSASI'], true) ? save_evidence($_FILES['evidence'] ?? ['error' => UPLOAD_ERR_NO_FILE]) : null;
            $pdo->beginTransaction();
            $ex = $pdo->prepare('SELECT id, status FROM attendance WHERE session_id=? AND student_id=? AND attendance_date=? FOR UPDATE');
            $ex->execute([$sessionId, $sid, $date]); $row = $ex->fetch();
            if ($row) {   // koreksi: simpan riwayat perubahan
                if ($row['status'] !== $new) {
                    $pdo->prepare('UPDATE attendance SET status=?, note=?, method=\'manual\' WHERE id=?')->execute([$new, mb_substr($reason, 0, 255), $row['id']]);
                    $pdo->prepare('INSERT INTO attendance_corrections (attendance_id,old_status,new_status,reason,corrected_by) VALUES (?,?,?,?,?)')->execute([$row['id'], $row['status'], $new, mb_substr($reason, 0, 255), $u['id']]);
                }
                $aid = (int)$row['id'];
            } else {
                $pdo->prepare('INSERT INTO attendance (session_id,student_id,class_id,attendance_date,status,scanned_at,scanned_by,method,note) VALUES (?,?,?,?,?,NOW(),?,\'manual\',?)')
                    ->execute([$sessionId, $sid, $classId, $date, $new, $u['id'], mb_substr($reason, 0, 255)]);
                $aid = (int)$pdo->lastInsertId();
            }
            if (in_array($new, ['IZIN', 'SAKIT', 'DISPENSASI'], true))
                $pdo->prepare('INSERT INTO attendance_permissions (attendance_id,student_id,type,start_date,end_date,reason,evidence_file,created_by) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$aid, $sid, $new, $date, $date, mb_substr($reason, 0, 255), $file, $u['id']]);
            $pdo->commit();
            log_activity($row ? 'correct' : 'manual', 'attendance', $aid, ($row['status'] ?? '-') . ' -> ' . $new . ': ' . $reason);
            flash('ok', $row ? 'Absensi dikoreksi.' : 'Absensi dicatat.');
        } elseif ($act === 'mark_alpa') {
            $st = $pdo->prepare("INSERT IGNORE INTO attendance (session_id,student_id,class_id,attendance_date,status,scanned_at,scanned_by,method,note)
                SELECT ?, s.id, s.class_id, ?, 'ALPA', NOW(), ?, 'manual', 'Ditandai alpa otomatis' FROM students s WHERE s.class_id=? AND s.status='aktif'");
            $st->execute([$sessionId, $date, $u['id'], $classId]);
            log_activity('mark_alpa', 'attendance', null, "kelas $classId sesi $sessionId $date: " . $st->rowCount());
            flash('ok', $st->rowCount() . ' siswa yang belum tercatat ditandai ALPA.');
        }
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('err', $e instanceof PDOException ? 'Kesalahan database.' : $e->getMessage()); }
    redirect('modules/attendance/manual.php?' . http_build_query(['class_id' => $classId, 'date' => $date, 'session_id' => $sessionId]));
}

$rows = [];
if ($classId && $sessionId) {
    $q = $pdo->prepare("SELECT s.id, s.nis, s.full_name, a.status, a.method, a.note, a.scanned_at FROM students s
        LEFT JOIN attendance a ON a.student_id=s.id AND a.session_id=? AND a.attendance_date=? WHERE s.class_id=? AND s.status='aktif' ORDER BY s.full_name");
    $q->execute([$sessionId, $date, $classId]); $rows = $q->fetchAll();
}
render_header('Absensi Manual & Koreksi', 'manual'); ?>
<div class="card stat-card"><div class="card-body">
  <form method="get" class="row g-2 mb-3">
    <div class="col-md-3"><label class="form-label small">Kelas</label><select name="class_id" class="form-select" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $classId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small">Tanggal</label><input type="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>" class="form-control" onchange="this.form.submit()"></div>
    <div class="col-md-4"><label class="form-label small">Sesi</label><select name="session_id" class="form-select" onchange="this.form.submit()"><?php foreach ($sessions as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $sessionId == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 d-flex align-items-end"></div>
  </form>
  <?php if ($rows): ?>
  <form method="post" class="confirm-form mb-3" data-msg="Semua siswa yang belum tercatat pada sesi ini akan ditandai ALPA."><?= csrf_field() ?><input type="hidden" name="action" value="mark_alpa">
    <input type="hidden" name="class_id" value="<?= $classId ?>"><input type="hidden" name="date" value="<?= e($date) ?>"><input type="hidden" name="session_id" value="<?= $sessionId ?>">
    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-person-x"></i> Tandai yang belum hadir sebagai ALPA</button></form>
  <?php endif; ?>
  <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>NIS</th><th>Nama</th><th>Status</th><th>Catatan</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $cls = ['HADIR' => 'success', 'TERLAMBAT' => 'warning', 'IZIN' => 'info', 'SAKIT' => 'info', 'ALPA' => 'danger', 'DISPENSASI' => 'secondary'][$r['status']] ?? 'light'; ?>
    <tr><td><?= e($r['nis']) ?></td><td class="fw-semibold"><?= e($r['full_name']) ?></td>
    <td><?= $r['status'] ? '<span class="badge text-bg-' . $cls . '">' . e($r['status']) . '</span> <small class="text-muted">' . e($r['method']) . '</small>' : '<span class="text-muted small">belum tercatat</span>' ?></td>
    <td class="small text-muted"><?= e($r['note']) ?></td>
    <td class="text-end"><button class="btn btn-sm btn-outline-primary" onclick='openSet(<?= (int)$r['id'] ?>,<?= json_encode($r['full_name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>,<?= json_encode($r['status']) ?>)'><?= $r['status'] ? 'Koreksi' : 'Input' ?></button></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div></div>
<div class="modal fade" id="fm"><div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" value="set"><input type="hidden" name="student_id" id="f_sid">
  <input type="hidden" name="class_id" value="<?= $classId ?>"><input type="hidden" name="date" value="<?= e($date) ?>"><input type="hidden" name="session_id" value="<?= $sessionId ?>">
  <div class="modal-header"><h5 class="modal-title" id="f_title">Absensi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-3">
    <div><label class="form-label">Status</label><select name="status" id="f_status" class="form-select" onchange="evBox.classList.toggle('d-none',!['IZIN','SAKIT','DISPENSASI'].includes(this.value))"><?php foreach ($STATUS as $s): ?><option><?= $s ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label">Alasan <span class="text-danger">*</span></label><input name="reason" class="form-control" required maxlength="255"></div>
    <div id="evBox" class="d-none"><label class="form-label">Bukti pendukung (opsional; JPG/PNG/WEBP/PDF, maks 2MB)</label><input type="file" name="evidence" accept="image/jpeg,image/png,image/webp,application/pdf" class="form-control"></div>
  </div><div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openSet(id,name,cur){f_sid.value=id;f_title.textContent=name;f_status.value=cur||'IZIN';f_status.dispatchEvent(new Event('change'));new bootstrap.Modal(fm).show()}
</script>

<?php
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
require_once __DIR__ . '/../../app/helpers/qr.php';
$u = require_login(['super_admin', 'admin']);
$pdo = db(); $ay = active_ay_id($pdo);

// ---- filter ----
$fDept = (int)($_GET['dept'] ?? 0); $fClass = (int)($_GET['class'] ?? 0);
function student_query(PDO $pdo, int $fDept, int $fClass): array {
    $sql = "SELECT s.*, c.name AS class_name, c.department_id, d.code AS dept_code, u.username AS leader_user
            FROM students s JOIN classes c ON c.id=s.class_id JOIN departments d ON d.id=c.department_id LEFT JOIN users u ON u.id=s.user_id WHERE 1=1";
    $p = [];
    if ($fDept) { $sql .= ' AND c.department_id=?'; $p[] = $fDept; }
    if ($fClass) { $sql .= ' AND s.class_id=?'; $p[] = $fClass; }
    $st = $pdo->prepare($sql . ' ORDER BY c.name, s.full_name'); $st->execute($p);
    return $st->fetchAll();
}

// ---- template & export CSV ----
$CSV_HEAD = ['nis','nisn','nama','jk','tempat_lahir','tanggal_lahir','alamat','hp','kelas','tahun_masuk'];
if (isset($_GET['template']) || isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . (isset($_GET['template']) ? 'template_siswa' : 'data_siswa_' . date('Ymd')) . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, $CSV_HEAD);
    if (isset($_GET['template'])) fputcsv($out, ['12345', '0012345678', 'Contoh Siswa', 'L', 'Mopuya', '2010-05-17', 'Jl. Contoh', '08123456789', 'X TJKT 1', '2026']);
    else foreach (student_query($pdo, $fDept, $fClass) as $r)
        fputcsv($out, [$r['nis'], $r['nisn'], $r['full_name'], $r['gender'], $r['birth_place'], $r['birth_date'], $r['address'], $r['phone'], $r['class_name'], $r['entry_year']]);
    exit;
}

// ---- helper: akun ketua kelas ----
function sync_leader(PDO $pdo, int $sid, int $classId, ?int $ay): void {
    $leader = isset($_POST['is_class_leader']); $lu = trim($_POST['leader_username'] ?? ''); $lp = (string)($_POST['leader_password'] ?? '');
    $s = $pdo->prepare('SELECT user_id, full_name FROM students WHERE id=?'); $s->execute([$sid]); $row = $s->fetch(); $uid = $row['user_id'] ? (int)$row['user_id'] : 0;
    if (!$leader) {
        if ($uid) { $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]); }
        $pdo->prepare('UPDATE students SET is_class_leader=0, user_id=NULL WHERE id=?')->execute([$sid]);
        return;
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $lu)) throw new RuntimeException('Username ketua kelas 3-50 karakter (huruf, angka, . _ -).');
    if ($uid) {
        $pdo->prepare('UPDATE users SET username=?, full_name=? WHERE id=?')->execute([$lu, $row['full_name'], $uid]);
        if ($lp !== '') { if (strlen($lp) < 6) throw new RuntimeException('Password minimal 6 karakter.'); $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($lp, PASSWORD_DEFAULT), $uid]); }
    } else {
        if (strlen($lp) < 6) throw new RuntimeException('Password ketua kelas minimal 6 karakter.');
        $pdo->prepare("INSERT INTO users (role_id,username,password_hash,full_name) SELECT id,?,?,? FROM roles WHERE code='ketua_kelas'")->execute([$lu, password_hash($lp, PASSWORD_DEFAULT), $row['full_name']]);
        $uid = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE students SET is_class_leader=1, user_id=? WHERE id=?')->execute([$uid, $sid]);
    $pdo->prepare('DELETE FROM class_assignments WHERE user_id=?')->execute([$uid]);
    $pdo->prepare('INSERT INTO class_assignments (user_id,class_id,academic_year_id) VALUES (?,?,?)')->execute([$uid, $classId, $ay]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $back = 'modules/students/index.php' . ($fClass ? "?class=$fClass" : '');
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $nis = trim($_POST['nis'] ?? ''); $nisn = trim($_POST['nisn'] ?? ''); $name = trim($_POST['full_name'] ?? '');
            $gender = $_POST['gender'] ?? ''; $class = (int)($_POST['class_id'] ?? 0);
            if (!preg_match('/^\d{3,20}$/', $nis) || !preg_match('/^\d{5,20}$/', $nisn)) throw new RuntimeException('NIS dan NISN harus berupa angka.');
            if ($name === '' || !in_array($gender, ['L', 'P'], true) || !$class) throw new RuntimeException('Nama, jenis kelamin, dan kelas wajib diisi.');
            $bd = trim($_POST['birth_date'] ?? ''); if ($bd !== '' && !DateTime::createFromFormat('Y-m-d', $bd)) throw new RuntimeException('Tanggal lahir tidak valid.');
            $status = in_array($_POST['status'] ?? '', ['aktif', 'lulus', 'pindah', 'keluar'], true) ? $_POST['status'] : 'aktif';
            $photo = save_uploaded_photo($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'students');
            $d = [$nis, $nisn, $name, $gender, trim($_POST['birth_place'] ?? '') ?: null, $bd ?: null, trim($_POST['address'] ?? '') ?: null, trim($_POST['phone'] ?? '') ?: null, $class, (int)($_POST['entry_year'] ?? 0) ?: null, $status];
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE students SET nis=?,nisn=?,full_name=?,gender=?,birth_place=?,birth_date=?,address=?,phone=?,class_id=?,entry_year=?,status=? WHERE id=?')->execute([...$d, $id]);
                if ($photo) $pdo->prepare('UPDATE students SET photo=? WHERE id=?')->execute([$photo, $id]);
            } else {
                $pdo->prepare('INSERT INTO students (nis,nisn,full_name,gender,birth_place,birth_date,address,phone,class_id,entry_year,status,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$d, $photo]);
                $id = (int)$pdo->lastInsertId();
                create_qr_for_student($pdo, $id, $u['id']);   // QR otomatis saat siswa ditambahkan
            }
            sync_leader($pdo, $id, $class, $ay);
            $pdo->commit();
            log_activity('save', 'students', $id, $nis);
            flash('ok', 'Data siswa disimpan.');
        } elseif ($act === 'delete') {
            $id = (int)$_POST['id'];
            $a = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE student_id=?'); $a->execute([$id]);
            if ($a->fetchColumn() > 0) throw new RuntimeException('Siswa memiliki riwayat absensi. Ubah statusnya menjadi pindah/keluar saja.');
            $pdo->beginTransaction();
            $s = $pdo->prepare('SELECT user_id FROM students WHERE id=?'); $s->execute([$id]); $uid = $s->fetchColumn();
            $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$id]);
            if ($uid) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            $pdo->commit();
            log_activity('delete', 'students', $id);
            flash('ok', 'Siswa dihapus.');
        } elseif ($act === 'regen') {
            $id = (int)$_POST['id'];
            create_qr_for_student($pdo, $id, $u['id']);
            log_activity('regen_qr', 'students', $id);
            flash('ok', 'QR Code baru dibuat. Kartu lama tidak berlaku lagi.');
        } elseif ($act === 'import') {
            if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pilih file CSV.');
            $h = fopen($_FILES['csv']['tmp_name'], 'r');
            $first = fgets($h); rewind($h);
            $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $head = array_map(fn($x) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $x))), fgetcsv($h, 0, $delim) ?: []);
            foreach (['nis', 'nisn', 'nama', 'jk', 'kelas'] as $req) if (!in_array($req, $head, true)) throw new RuntimeException("Kolom '$req' tidak ditemukan di header CSV.");
            $classMap = []; foreach ($pdo->query('SELECT id, name FROM classes') as $c) $classMap[mb_strtolower($c['name'])] = (int)$c['id'];
            $ok = 0; $bad = []; $line = 1;
            $ins = $pdo->prepare('INSERT INTO students (nis,nisn,full_name,gender,birth_place,birth_date,address,phone,class_id,entry_year) VALUES (?,?,?,?,?,?,?,?,?,?)');
            while (($row = fgetcsv($h, 0, $delim)) !== false) {
                $line++; if (count($row) < 2) continue;
                $r = array_combine($head, array_pad(array_slice($row, 0, count($head)), count($head), ''));
                $cid = $classMap[mb_strtolower(trim($r['kelas']))] ?? 0; $jk = strtoupper(trim($r['jk']));
                $bd = trim($r['tanggal_lahir'] ?? '');
                if (!preg_match('/^\d{3,20}$/', trim($r['nis'])) || !preg_match('/^\d{5,20}$/', trim($r['nisn'])) || trim($r['nama']) === '' || !in_array($jk, ['L', 'P'], true) || !$cid || ($bd !== '' && !DateTime::createFromFormat('Y-m-d', $bd))) { $bad[] = $line; continue; }
                try {
                    $pdo->beginTransaction();
                    $ins->execute([trim($r['nis']), trim($r['nisn']), trim($r['nama']), $jk, trim($r['tempat_lahir'] ?? '') ?: null, $bd ?: null, trim($r['alamat'] ?? '') ?: null, trim($r['hp'] ?? '') ?: null, $cid, (int)($r['tahun_masuk'] ?? 0) ?: null]);
                    create_qr_for_student($pdo, (int)$pdo->lastInsertId(), $u['id']);
                    $pdo->commit(); $ok++;
                } catch (PDOException $e) { $pdo->rollBack(); $bad[] = $line; }
            }
            fclose($h);
            log_activity('import', 'students', null, "$ok berhasil");
            flash('ok', "$ok siswa diimpor." . ($bad ? ' Baris dilewati (data tidak valid/duplikat): ' . implode(', ', array_slice($bad, 0, 20)) . (count($bad) > 20 ? ' ...' : '') : ''));
        }
    } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('err', $e->getCode() === '23000' ? 'NIS/NISN sudah terdaftar atau username sudah dipakai.' : 'Kesalahan database.'); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('err', $e->getMessage()); }
    redirect($back);
}

$depts = $pdo->query('SELECT id, code FROM departments ORDER BY code')->fetchAll();
$classes = $pdo->query('SELECT id, name, department_id FROM classes ORDER BY name')->fetchAll();
$rows = student_query($pdo, $fDept, $fClass);
render_header('Manajemen Siswa', 'students'); ?>
<div class="card stat-card"><div class="card-body">
  <form class="row g-2 mb-3" method="get">
    <div class="col-6 col-md-3"><select name="dept" class="form-select" onchange="this.form.class.value='';this.form.submit()"><option value="0">Semua Jurusan</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept == $d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><select name="class" class="form-select" onchange="this.form.submit()"><option value="0">Semua Kelas</option><?php foreach ($classes as $c) if (!$fDept || $c['department_id'] == $fDept): ?><option value="<?= (int)$c['id'] ?>" <?= $fClass == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endif; ?></select></div>
    <div class="col-12 col-md-6 d-flex flex-wrap gap-2 justify-content-md-end">
      <?php if ($fClass): ?><a class="btn btn-success" target="_blank" href="<?= url('modules/qrcode/cards.php?class_id=' . $fClass) ?>"><i class="bi bi-printer"></i> Cetak Kartu Kelas</a><?php endif; ?>
      <a class="btn btn-outline-success" href="<?= url('modules/qrcode/zip.php' . ($fClass ? '?class_id=' . $fClass : '')) ?>"><i class="bi bi-file-earmark-zip"></i> Unduh QR (ZIP)</a>
      <a class="btn btn-outline-secondary" href="?<?= http_build_query(['export' => 1, 'dept' => $fDept, 'class' => $fClass]) ?>"><i class="bi bi-download"></i> Export CSV</a>
      <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#im"><i class="bi bi-upload"></i> Import CSV</button>
      <button type="button" class="btn btn-primary" onclick="openForm()"><i class="bi bi-plus-lg"></i> Tambah</button>
    </div>
  </form>
  <div class="table-responsive"><table class="table table-hover dt align-middle"><thead><tr><th></th><th>NIS</th><th>NISN</th><th>Nama</th><th>L/P</th><th>Kelas</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><?php if ($r['photo']): ?><img src="<?= url('uploads/students/' . e($r['photo'])) ?>" width="36" height="36" class="rounded-circle object-fit-cover"><?php else: ?><span class="stat-icon" style="width:36px;height:36px;font-size:1rem"><?= e(mb_substr($r['full_name'], 0, 1)) ?></span><?php endif; ?></td>
    <td><?= e($r['nis']) ?></td><td><?= e($r['nisn']) ?></td>
    <td class="fw-semibold"><?= e($r['full_name']) ?><?= $r['is_class_leader'] ? ' <span class="badge text-bg-info">Ketua</span>' : '' ?></td>
    <td><?= e($r['gender']) ?></td><td><?= e($r['class_name']) ?></td>
    <td><span class="badge text-bg-<?= $r['status'] === 'aktif' ? 'success' : 'secondary' ?>"><?= e($r['status']) ?></span></td>
    <td class="text-end text-nowrap">
      <a class="btn btn-sm btn-outline-success" target="_blank" href="<?= url('modules/qrcode/cards.php?student_id=' . (int)$r['id']) ?>" title="Kartu QR"><i class="bi bi-qr-code"></i></a>
      <button class="btn btn-sm btn-outline-primary" onclick='openForm(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline confirm-form" data-msg="Buat QR baru? Kartu lama tidak akan berlaku."><?= csrf_field() ?><input type="hidden" name="action" value="regen"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-warning" title="Regenerasi QR"><i class="bi bi-arrow-repeat"></i></button></form>
      <form method="post" class="d-inline confirm-form" data-msg="Hapus siswa ini?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </td></tr><?php endforeach; ?>
  </tbody></table></div>
</div></div>

<div class="modal fade" id="fm"><div class="modal-dialog modal-lg"><form method="post" enctype="multipart/form-data" class="modal-content"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
  <div class="modal-header"><h5 class="modal-title">Data Siswa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-4"><label class="form-label">NIS</label><input name="nis" id="f_nis" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">NISN</label><input name="nisn" id="f_nisn" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Jenis Kelamin</label><select name="gender" id="f_gender" class="form-select"><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></div>
    <div class="col-md-8"><label class="form-label">Nama Lengkap</label><input name="full_name" id="f_name" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Kelas</label><select name="class_id" id="f_class" class="form-select" required><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fClass == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Tempat Lahir</label><input name="birth_place" id="f_bp" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">Tanggal Lahir</label><input type="date" name="birth_date" id="f_bd" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">No. HP</label><input name="phone" id="f_phone" class="form-control"></div>
    <div class="col-md-8"><label class="form-label">Alamat</label><input name="address" id="f_addr" class="form-control"></div>
    <div class="col-md-2"><label class="form-label">Tahun Masuk</label><input name="entry_year" id="f_year" class="form-control" maxlength="4"></div>
    <div class="col-md-2"><label class="form-label">Status</label><select name="status" id="f_status" class="form-select"><option>aktif</option><option>lulus</option><option>pindah</option><option>keluar</option></select></div>
    <div class="col-12"><label class="form-label">Foto (JPG/PNG/WEBP, maks 2MB)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control"></div>
    <div class="col-12"><hr><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_class_leader" id="f_leader" onchange="leaderBox.classList.toggle('d-none',!this.checked)"><label class="form-check-label" for="f_leader">Jadikan Ketua Kelas (buat akun login)</label></div>
      <div class="row g-2 d-none" id="leaderBox"><div class="col-md-6"><label class="form-label">Username</label><input name="leader_username" id="f_lu" class="form-control"></div><div class="col-md-6"><label class="form-label">Password <small class="text-muted">(kosongkan jika tidak diubah)</small></label><input type="password" name="leader_password" class="form-control" autocomplete="new-password"></div></div></div>
  </div></div>
  <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>

<div class="modal fade" id="im"><div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="import">
  <div class="modal-header"><h5 class="modal-title">Import CSV Siswa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-2"><input type="file" name="csv" accept=".csv" class="form-control" required>
    <div class="small text-muted">Header: <code><?= e(implode(',', $CSV_HEAD)) ?></code>. Nama kelas harus sama dengan data kelas. Tanggal format YYYY-MM-DD. <a href="?template=1">Unduh template</a>. Untuk file Excel, simpan dulu sebagai CSV.</div></div>
  <div class="modal-footer"><button class="btn btn-primary">Import</button></div>
</form></div></div>
<?php render_footer(); ?>
<script>
function openForm(r){r=r||{};const v=(el,x)=>el.value=x==null?'':x;
v(f_id,r.id);v(f_nis,r.nis);v(f_nisn,r.nisn);v(f_name,r.full_name);f_gender.value=r.gender||'L';if(r.class_id)f_class.value=r.class_id;
v(f_bp,r.birth_place);v(f_bd,r.birth_date);v(f_phone,r.phone);v(f_addr,r.address);v(f_year,r.entry_year);f_status.value=r.status||'aktif';
f_leader.checked=r.is_class_leader==1;leaderBox.classList.toggle('d-none',!f_leader.checked);v(f_lu,r.leader_user);new bootstrap.Modal(fm).show()}
</script>

<?php
// Gunakan: render_header($title, $active); ...konten...; render_footer();
function render_header(string $title, string $active = ''): void {
    $u = $_SESSION['user'];
    $base = in_array($u['role'], ['super_admin','admin']) ? 'admin' : ($u['role'] === 'guru' ? 'teacher' : 'ketua_kelas');
    $menu = [['dashboard','Dashboard','bi-speedometer2',"$base/index.php"]];
    if (in_array($u['role'], ['super_admin','admin'])) {
        $menu[] = ['students','Siswa','bi-people','modules/students/index.php'];
        $menu[] = ['departments','Jurusan','bi-diagram-3','modules/departments/index.php'];
        $menu[] = ['classes','Kelas','bi-building','modules/classes/index.php'];
        $menu[] = ['teachers','Guru','bi-person-badge','modules/teachers/index.php'];
        $menu[] = ['sessions','Sesi Absensi','bi-clock-history','modules/attendance/sessions.php'];
        $menu[] = ['manual','Absensi Manual','bi-pencil-square','modules/attendance/manual.php'];
        $menu[] = ['reports','Laporan','bi-file-earmark-bar-graph','modules/reports/index.php'];
        $menu[] = ['settings','Pengaturan','bi-gear','modules/settings/index.php'];
        $menu[] = ['academic','Tahun Ajaran','bi-calendar3','modules/settings/academic.php'];
        $menu[] = ['holidays','Hari Libur','bi-calendar-x','modules/settings/holidays.php'];
        if ($u['role'] === 'super_admin') $menu[] = ['backup','Backup & Restore','bi-database-down','modules/backup/index.php'];
    }
    if ($u['role'] === 'guru') { $menu[] = ['manual','Absensi Manual','bi-pencil-square','modules/attendance/manual.php']; $menu[] = ['reports','Laporan','bi-file-earmark-bar-graph','modules/reports/index.php']; }
    if (in_array($u['role'], ['guru','ketua_kelas'])) $menu[] = ['scanner','Scan Absensi','bi-qr-code-scan','modules/scanner/index.php'];
    $menu[] = ['profile','Profil Saya','bi-person-circle','modules/profile/index.php'];
    $menu[] = ['help','Panduan','bi-question-circle','modules/help/index.php'];
    ?><!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e(setting('school_name')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="<?= url('assets/css/app.css') ?>" rel="stylesheet">
<script>document.documentElement.dataset.bsTheme=localStorage.getItem('theme')||'light'</script>
</head><body>
<aside class="sidebar py-3" id="sidebar">
  <div class="px-4 pb-3 text-white fw-bold"><i class="bi bi-qr-code-scan text-info"></i> <?= e(setting('school_name')) ?></div>
  <?php foreach ($menu as $m): ?>
    <a href="<?= url($m[3]) ?>" class="<?= $active === $m[0] ? 'active' : '' ?>"><i class="bi <?= $m[2] ?>"></i> <?= e($m[1]) ?></a>
  <?php endforeach; ?>
  <a href="<?= url('auth/logout.php') ?>"><i class="bi bi-box-arrow-left"></i> Keluar</a>
</aside>
<div class="main-wrap">
  <nav class="navbar bg-body px-3 shadow-sm">
    <button class="btn d-lg-none" onclick="document.getElementById('sidebar').classList.toggle('show')"><i class="bi bi-list fs-4"></i></button>
    <span class="fw-semibold"><?= e($title) ?></span>
    <div class="ms-auto d-flex align-items-center gap-3">
      <button class="btn btn-sm btn-outline-secondary" onclick="var t=document.documentElement.dataset.bsTheme==='dark'?'light':'dark';document.documentElement.dataset.bsTheme=t;localStorage.setItem('theme',t)"><i class="bi bi-moon-stars"></i></button>
      <a href="<?= url('modules/profile/index.php') ?>" class="d-flex align-items-center gap-2 text-decoration-none text-body">
        <?php if (!empty($u['photo'])): ?><img src="<?= url('uploads/users/' . e($u['photo'])) ?>" width="30" height="30" class="rounded-circle object-fit-cover" alt="">
        <?php else: ?><span class="stat-icon" style="width:30px;height:30px;font-size:.9rem;border-radius:50%"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span><?php endif; ?>
        <span class="small d-none d-md-inline"><?= e($u['name']) ?></span></a>
    </div>
  </nav>
  <main class="p-3 p-md-4">
  <?php if (!empty($_SESSION['weak_pw']) && $active !== 'profile'): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2"><i class="bi bi-exclamation-triangle-fill"></i>
      <div>Anda masih memakai password bawaan/lemah. <a href="<?= url('modules/profile/index.php#password') ?>" class="alert-link">Ganti password sekarang</a>.</div></div>
  <?php endif; ?>
<?php }

// Tab navigasi untuk kelompok halaman Pengaturan.
function settings_tabs(string $active): void {
    $tabs = ['settings' => ['Sekolah', 'bi-building', 'modules/settings/index.php'], 'academic' => ['Tahun Ajaran & Semester', 'bi-calendar3', 'modules/settings/academic.php'], 'holidays' => ['Hari Libur', 'bi-calendar-x', 'modules/settings/holidays.php']];
    echo '<ul class="nav nav-pills mb-3 flex-wrap gap-1">';
    foreach ($tabs as $k => [$label, $icon, $path]) echo '<li class="nav-item"><a class="nav-link ' . ($k === $active ? 'active' : '') . '" href="' . e(url($path)) . '"><i class="bi ' . $icon . '"></i> ' . e($label) . '</a></li>';
    echo '</ul>';
}
function render_footer(): void { ?>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$('.dt').DataTable({language:{search:'Cari:',lengthMenu:'Tampil _MENU_',info:'_START_-_END_ dari _TOTAL_',paginate:{previous:'‹',next:'›'},zeroRecords:'Data tidak ditemukan'}});
$(document).on('submit','.confirm-form',function(ev){ev.preventDefault();var f=this;Swal.fire({title:'Yakin?',text:$(f).data('msg')||'Data akan dihapus.',icon:'warning',showCancelButton:true,confirmButtonText:'Ya',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed)f.submit()})});
<?php if ($m = flash('ok')): ?>Swal.fire({icon:'success',title:<?= json_encode($m) ?>,timer:1800,showConfirmButton:false});<?php endif; ?>
<?php if ($m = flash('err')): ?>Swal.fire({icon:'error',title:'Gagal',text:<?= json_encode($m) ?>});<?php endif; ?>
</script>
<?php register_shutdown_function(function(){ echo "\n</body></html>"; }); }

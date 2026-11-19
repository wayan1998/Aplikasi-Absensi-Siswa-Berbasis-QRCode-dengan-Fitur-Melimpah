<?php
// Panduan penggunaan, disesuaikan dengan peran pengguna.
require_once __DIR__ . '/../../app/middleware/auth.php';
require_once __DIR__ . '/../../app/views/layout.php';
$u = require_login();
$isAdmin = in_array($u['role'], ['super_admin', 'admin'], true);
$isGuru = $u['role'] === 'guru';
$isKetua = $u['role'] === 'ketua_kelas';

// [judul, isi HTML, tampil?]
$sections = [
  ['Memulai', '<ol><li>Buka alamat aplikasi, login dengan username & password dari admin.</li>
    <li>Ganti password bawaan lewat menu <b>Profil Saya</b> (minimal 8 karakter, huruf + angka).</li>
    <li>Gunakan tombol <i class="bi bi-moon-stars"></i> di pojok kanan atas untuk mode gelap.</li>
    <li>Anda otomatis keluar bila tidak aktif beberapa menit (diatur admin di Pengaturan).</li></ol>', true],
  ['Alur kerja harian (ringkas)', '<ol><li><b>Admin</b> memastikan sesi absensi aktif dan kartu QR siswa sudah dicetak.</li>
    <li>Siswa menunjukkan kartu QR; <b>guru / ketua kelas</b> membuka <i>Scan Absensi</i>, memilih kelas & sesi, lalu <i>Mulai Scan</i>.</li>
    <li>Siswa yang tidak hadir karena izin/sakit/dispensasi dicatat lewat <i>Absensi Manual</i>.</li>
    <li>Setelah sesi ditutup, siswa yang belum tercatat ditandai <b>ALPA</b> (tombol di Absensi Manual).</li>
    <li>Rekap dilihat di <i>Laporan</i> atau Dashboard.</li></ol>', true],
  ['Scan QR (guru & ketua kelas)', '<ul><li>Kamera butuh <b>HTTPS</b> atau <code>localhost</code>. Izinkan akses kamera saat diminta browser.</li>
    <li>Scan hanya berhasil jika sesi sedang <b>dibuka</b> (jam mulai-selesai), hari itu bukan hari libur, dan siswa ada di kelas yang dipilih.</li>
    <li>Scan sebelum batas "terlambat" tercatat <span class="badge text-bg-success">HADIR</span>, sesudahnya <span class="badge text-bg-warning">TERLAMBAT</span>.</li>
    <li>Scan ulang untuk siswa yang sama pada sesi & tanggal yang sama ditolak (tidak ada data ganda).</li>
    <li>Kartu hilang/disalahgunakan? Admin membuat ulang QR siswa; QR lama langsung tidak berlaku.</li>
    <li>QR buram: bersihkan lensa, naikkan kecerahan layar/cetakan, dekatkan 15-25 cm.</li></ul>', $isGuru || $isKetua || $isAdmin],
  ['Arti status kehadiran', '<table class="table table-sm mb-0"><tbody>
    <tr><td><span class="badge text-bg-success">HADIR</span></td><td>Scan sebelum batas terlambat.</td></tr>
    <tr><td><span class="badge text-bg-warning">TERLAMBAT</span></td><td>Scan setelah batas terlambat (dihitung hadir pada persentase kehadiran).</td></tr>
    <tr><td><span class="badge text-bg-info">IZIN</span> <span class="badge text-bg-info">SAKIT</span></td><td>Dicatat manual dengan alasan; bukti (JPG/PNG/WEBP/PDF, maks 2 MB) boleh dilampirkan.</td></tr>
    <tr><td><span class="badge text-bg-secondary">DISPENSASI</span></td><td>Tidak hadir karena tugas sekolah (lomba, kegiatan, dsb.).</td></tr>
    <tr><td><span class="badge text-bg-danger">ALPA</span></td><td>Tanpa keterangan.</td></tr></tbody></table>', true],
  ['Absensi manual & koreksi (admin & guru)', '<ul><li>Menu <b>Absensi Manual</b>: pilih kelas, tanggal, sesi, lalu <i>Input</i> atau <i>Koreksi</i> pada siswa.</li>
    <li>Alasan <b>wajib</b>. Setiap koreksi tersimpan riwayatnya (status lama, status baru, alasan, siapa yang mengoreksi).</li>
    <li>Guru hanya dapat mengelola kelas yang diampu. Ketua kelas tidak punya akses ke menu ini.</li>
    <li><i>Tandai yang belum hadir sebagai ALPA</i> memengaruhi semua siswa kelas itu yang belum tercatat pada sesi tersebut.</li></ul>', $isAdmin || $isGuru],
  ['Laporan (admin & guru)', '<ul><li>Pilih rentang tanggal, jurusan/kelas, status, atau cari nama/NIS. Tersedia rekap per siswa, kelas, jurusan, harian, dan detail.</li>
    <li>Rekap per siswa diurutkan sebagai ranking kedisiplinan.</li>
    <li><b>Cetak / PDF</b>: membuka halaman berkop sekolah; di dialog cetak pilih <i>Simpan sebagai PDF</i>.</li>
    <li><b>Export Excel</b>: berkas <code>.xls</code> (tabel HTML) yang terbuka di Excel/LibreOffice; Excel mungkin menampilkan peringatan format, pilih <i>Ya/Buka</i>.</li>
    <li>Kop & tanda tangan diambil dari <i>Pengaturan</i> (nama sekolah, NPSN, alamat, kota, kepala sekolah, NIP, logo).</li></ul>', $isAdmin || $isGuru],
  ['Cetak kartu & unduh QR (admin & guru)', '<ul><li>Menu Kelas atau Siswa &rarr; ikon printer. Di dialog cetak: kertas <b>F4 (215 x 330 mm)</b>, margin <b>None</b>, skala <b>100%</b>, aktifkan <b>Background graphics</b>. 6 kartu per lembar.</li>
    <li><b>Unduh QR (ZIP)</b>: satu file ZIP berisi gambar PNG QR setiap siswa (nama file <code>NIS_Nama.png</code>) plus <code>daftar.csv</code>.</li>
    <li>Perlakukan kartu & file QR sebagai data rahasia: siapa pun yang memegang QR dapat discan atas nama siswa tersebut.</li></ul>', $isAdmin || $isGuru],
  ['Data master (admin)', '<ul><li><b>Jurusan, Kelas, Guru, Siswa</b>: tambah/ubah lewat tombol pada tiap halaman. Siswa dapat diimpor dari CSV (nama kelas harus identik dengan data kelas).</li>
    <li>Guru diberi kelas yang diampu; ketua kelas dibuat dari form siswa (centang <i>Ketua kelas</i>).</li>
    <li><b>Sesi Absensi</b>: atur jam mulai/selesai, batas terlambat, hari aktif, dan cakupan (semua kelas / jurusan / satu kelas).</li>
    <li>Siswa yang pindah/lulus: ubah statusnya (jangan dihapus) agar riwayat absensi tetap utuh.</li></ul>', $isAdmin],
  ['Pengaturan, tahun ajaran, hari libur (admin)', '<ul><li><b>Pengaturan</b>: identitas sekolah, logo, batas logout otomatis, batas scan per menit.</li>
    <li><b>Tahun Ajaran</b>: hanya satu tahun ajaran dan satu semester aktif. Ganti saat pergantian semester/tahun ajaran.</li>
    <li><b>Hari Libur</b>: tambah satu tanggal atau rentang (opsi lewati Sabtu-Minggu). Pada hari libur, scan ditolak.</li></ul>', $isAdmin],
  ['Backup & restore (Super Admin)', '<ul><li>Buat backup rutin (mis. mingguan, dan sebelum perubahan besar) lalu <b>unduh & simpan di luar server</b>.</li>
    <li>File upload (foto, logo, bukti) tidak termasuk file .sql: unduh juga <i>ZIP Upload</i>.</li>
    <li><b>Restore menimpa seluruh data.</b> Sistem meminta password + ketik <code>RESTORE</code>, membuat backup pengaman otomatis, lalu Anda diminta login ulang.</li>
    <li>Hanya file .sql buatan aplikasi ini yang diterima.</li></ul>', $u['role'] === 'super_admin'],
  ['Masalah umum', '<table class="table table-sm mb-0"><tbody>
    <tr><th class="w-25">Kamera tidak menyala</th><td>Pastikan HTTPS/localhost, izinkan kamera di pengaturan situs browser, tutup aplikasi lain yang memakai kamera.</td></tr>
    <tr><th>"Sesi tidak berlaku / ditutup"</th><td>Cek jam sesi, hari aktif sesi, kelas yang dipilih, dan apakah hari ini terdaftar libur.</td></tr>
    <tr><th>"Anda tidak berhak untuk kelas ini"</th><td>Minta admin menambahkan kelas tersebut pada penugasan guru/ketua kelas.</td></tr>
    <tr><th>Halaman "Sesi Formulir Kedaluwarsa" (419)</th><td>Muat ulang halaman lalu kirim ulang formulir.</td></tr>
    <tr><th>Akun terkunci</th><td>Tunggu 10 menit setelah 5 kali salah password, atau minta admin mengatur ulang password.</td></tr>
    <tr><th>Lupa password</th><td>Admin dapat mengatur ulang password guru/ketua kelas dari halaman Guru/Siswa; Super Admin dapat memperbaiki akun admin lewat database.</td></tr>
    <tr><th>Waktu scan salah</th><td>Jam server menentukan status hadir/terlambat; pastikan zona waktu server <code>Asia/Makassar</code>.</td></tr></tbody></table>', true],
];
render_header('Panduan Penggunaan', 'help'); ?>
<div class="mb-3"><h5 class="mb-1">Panduan Penggunaan</h5><div class="text-muted small">Peran Anda: <b><?= e(role_label($u['role'])) ?></b>. Hanya bagian yang relevan untuk peran Anda yang ditampilkan.</div></div>
<div class="accordion" id="acc">
<?php $i = 0; foreach ($sections as [$title, $html, $show]): if (!$show) continue; $i++; ?>
  <div class="accordion-item">
    <h2 class="accordion-header"><button class="accordion-button <?= $i > 1 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#s<?= $i ?>"><?= e($title) ?></button></h2>
    <div id="s<?= $i ?>" class="accordion-collapse collapse <?= $i === 1 ? 'show' : '' ?>" data-bs-parent="#acc"><div class="accordion-body"><?= $html ?></div></div>
  </div>
<?php endforeach; ?>
</div>
<?php render_footer(); ?>

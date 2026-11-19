# Absensi QR Code - SMK Negeri 1 Mopuya

Aplikasi absensi siswa berbasis QR Code (PHP 8.2+ & MySQL/MariaDB, tanpa framework).
Status: **Tahap 1-10 selesai** (database, login, CRUD master, QR & kartu, scanner, sesi, absensi manual & koreksi,
dashboard & grafik, laporan, pengaturan, tahun ajaran, hari libur, backup/restore, profil, halaman error, panduan, unduh QR ZIP).

## Kebutuhan server
- PHP 8.2 atau lebih baru dengan ekstensi `pdo_mysql`, `fileinfo`, `mbstring`, dan `zip` (untuk ZIP arsip upload).
- MySQL 5.7+/MariaDB 10.4+ (utf8mb4). Apache dengan `mod_rewrite` tidak wajib; `.htaccess` memakai `mod_headers` bila ada.
- Folder yang harus **dapat ditulis** oleh web server: `uploads/` (beserta subfolder `students`, `school`, `evidence`, `users`) dan `storage/` (`backups`, `logs`).
- HTTPS (atau `localhost`) untuk memakai kamera pada scanner.

## Instalasi
1. Salin folder ke `htdocs` / `public_html`.
2. Import `database/database.sql` lewat phpMyAdmin (membuat database `absensi_qr_smkn1mopuya`).
3. Edit `app/config/config.php`: `DB_USER`, `DB_PASS`, dan `BASE_URL` (tanpa slash di akhir; kosongkan bila di root domain).
4. Jika folder aplikasi bukan `/absensi-smkn1mopuya`, ubah juga tiga baris `ErrorDocument` di `.htaccess` agar halaman 403/404/500 tampil.
5. Pastikan izin tulis pada `uploads/` dan `storage/` (mis. `chmod -R 775`, pemilik = user web server).
6. Buka `/absensi-smkn1mopuya/` lalu login.
7. Login pertama: lengkapi **Pengaturan** (nama sekolah, NPSN, alamat, kepala sekolah, logo) dan **Tahun Ajaran**; **ganti password bawaan**.

### Akun awal (password: `password`)
- `superadmin` / `password`
- `admin` / `password`

Selama masih memakai password bawaan/lemah, banner peringatan tampil di setiap halaman sampai password diganti (menu *Profil Saya*).

### Peningkatan dari paket Tahap 8
Tidak ada perubahan skema tabel. Cukup timpa file aplikasi, lalu buat folder `storage/backups`, `storage/logs`, `uploads/users` (sudah ada di paket ini).
Pengaturan baru (`city`, `principal_nip`) dibuat otomatis saat pertama kali disimpan di halaman Pengaturan.

## Peran & akses
| Peran | Akses |
|---|---|
| Super Admin | Semua fitur + **Backup & Restore** |
| Admin Sekolah | Master data, sesi, absensi manual, laporan, pengaturan, tahun ajaran, hari libur |
| Guru | Scan, absensi manual & laporan (kelas yang diampu), cetak kartu/QR ZIP kelas yang diampu |
| Ketua Kelas | Scan untuk kelas yang ditugaskan saja |
Semua peran: Profil Saya (ubah profil & password) dan Panduan.

## Fitur Tahap 9-10
- **Pengaturan**: identitas sekolah & logo (dipakai di kop laporan dan kartu), kota & NIP kepala sekolah (tanda tangan), batas logout otomatis (5-480 menit), batas scan per menit.
- **Tahun ajaran & semester**: satu tahun ajaran dan satu semester aktif; tidak bisa menghapus yang aktif atau yang masih dipakai kelas.
- **Hari libur**: satu tanggal atau rentang (maks 62 hari, opsi lewati Sabtu-Minggu). Pada hari libur scan ditolak.
- **Backup & restore** (Super Admin): lihat bagian berikut.
- **Profil & ganti password**: nama, email, foto; password minimal 8 karakter berisi huruf dan angka, dibatasi percobaan.
- **Halaman error** 403/404/419/500 yang rapi; kesalahan server dicatat di `storage/logs/error.log` tanpa menampilkan detail ke pengguna (set `APP_DEBUG` true hanya saat pengembangan).
- **Panduan penggunaan** di menu *Panduan*, menyesuaikan peran.
- **Unduh QR (ZIP)**: Siswa -> *Unduh QR (ZIP)*, atau tombol *Unduh ZIP* di halaman cetak kartu. ZIP berisi PNG per siswa (`NIS_Nama.png`) dan `daftar.csv`; gambar dibuat di browser.

## Backup & restore
- *Buat Backup* menghasilkan `storage/backups/backup_YYYYMMDD_HHMMSS.sql` (murni PHP, tidak butuh `mysqldump`). **Unduh dan simpan di luar server.**
- File upload (foto, logo, bukti) tidak ikut di file .sql: gunakan *Unduh ZIP Upload*.
- *Restore* menimpa seluruh data. Pengamanan: hanya Super Admin; wajib password + ketik `RESTORE`; seluruh file divalidasi dahulu
  (hanya file buatan aplikasi ini, tabel yang dikenal, satu perintah per baris); backup pengaman `..._pre-restore.sql` dibuat otomatis;
  bila gagal di tengah jalan, sistem mencoba mengembalikan backup pengaman. Setelah sukses, semua pengguna harus login ulang.
- Batas unggah mengikuti `upload_max_filesize` PHP. Untuk file besar, salin lewat FTP ke `storage/backups/` (nama file harus berpola `backup_YYYYMMDD_HHMMSS.sql`) lalu pulihkan dari daftar.
- Jika menambah tabel baru di masa depan, tambahkan namanya ke `backup_tables()` di `app/helpers/backup.php`.

## Cetak kartu (F4, 6 kartu/lembar)
Menu Kelas -> ikon printer, atau Siswa -> pilih kelas -> *Cetak Kartu Kelas*.
Di dialog cetak: kertas **F4 (215 x 330 mm)**, margin **None**, skala **100%**, aktifkan **Background graphics**.
Jika F4 tidak ada di daftar printer, buat ukuran kustom 215 x 330 mm di pengaturan printer.

## Import siswa
Siswa -> Import CSV (unduh template dari dialog). Nama kelas harus identik dengan data kelas.

## Scanner QR
1. Admin: menu *Sesi Absensi* (sudah ada sesi pagi bawaan 06:30-09:00, terlambat setelah 07:15).
2. Admin: menu *Guru* untuk membuat akun guru + kelas diampu; ketua kelas dibuat dari form siswa.
3. Guru/ketua kelas login -> *Scan Absensi* -> pilih kelas & sesi -> *Mulai Scan*.
Validasi (server): token QR, kelas siswa, sesi aktif/jam/hari/libur, hak akses kelas, rate limit, UNIQUE(sesi, siswa, tanggal).

## Izin/sakit/dispensasi, koreksi, laporan
- *Absensi Manual* (admin & guru): status + alasan (+ bukti JPG/PNG/WEBP/PDF maks 2 MB); koreksi tercatat di `attendance_corrections`; tombol "tandai belum hadir sebagai ALPA".
- *Laporan*: rekap per siswa/kelas/jurusan/harian/detail. *Cetak / PDF* membuka halaman berkop -> Ctrl+P -> Simpan sebagai PDF. *Export Excel* menghasilkan `.xls` (tabel HTML; bukan `.xlsx` asli).

## Keamanan (ringkas)
Password di-hash (bcrypt), CSRF di semua form, prepared statement, sesi HttpOnly + timeout, kunci akun 10 menit setelah 5 kali gagal login,
rate limit login/scan/ganti password/restore, upload divalidasi tipe MIME & ukuran dengan nama acak, `uploads/` dan `storage/` tidak mengeksekusi PHP.
Folder `storage/` ditolak lewat `.htaccess` (Apache). **Di Nginx**, tambahkan aturan `deny all` untuk `/storage/`, `/database/`, dan `*.sql|*.md|*.log`;
lebih baik lagi pindahkan `storage/` ke luar document root dan ubah path di `app/helpers/backup.php` dan `app/helpers/errors.php`.

## Pengujian
Lihat `docs/CHECKLIST_PENGUJIAN.md` untuk daftar uji manual per fitur sebelum dipakai.
# Aplikasi-Absensi-Siswa-Berbasis-QRCode-dengan-Fitur-Melimpah

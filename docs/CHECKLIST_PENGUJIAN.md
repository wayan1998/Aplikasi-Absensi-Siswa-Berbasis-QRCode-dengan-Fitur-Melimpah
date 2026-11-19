# Checklist Pengujian Manual

Centang setiap butir setelah diuji di server lokal/hosting. Gunakan data uji (bukan data sebenarnya) dan **buat backup dulu**.
Catat hasil yang tidak sesuai beserta isi `storage/logs/error.log`.

## 0. Persiapan
- [ ] `database/database.sql` berhasil diimpor tanpa error; 19 tabel terbentuk.
- [ ] `app/config/config.php` (DB & BASE_URL) sudah disesuaikan; `/` mengarah ke halaman login.
- [ ] `uploads/` (students, school, evidence, users) dan `storage/` (backups, logs) dapat ditulis.
- [ ] Zona waktu server `Asia/Makassar` (cek jam pada hasil scan).

## 1. Login & keamanan dasar
- [ ] Login `superadmin`/`password` berhasil; banner "password bawaan" tampil di semua halaman.
- [ ] Password salah 5x -> akun terkunci ~10 menit; pesan tidak membocorkan apakah username ada.
- [ ] Logout menghapus sesi; tombol Back tidak membuka halaman terproteksi.
- [ ] Idle melebihi batas (set 5 menit di Pengaturan) -> diminta login ulang.
- [ ] Mengirim form tanpa token CSRF (mis. buka form lama setelah logout/login) -> halaman 419 yang rapi.
- [ ] Guru membuka `/modules/settings/index.php` atau `/modules/backup/index.php` -> halaman 403. Admin membuka Backup -> 403.
- [ ] Alamat tidak ada (`/xyz`) -> halaman 404 (butuh ErrorDocument di `.htaccess` sesuai BASE_URL).

## 2. Profil & password
- [ ] Ubah nama/email/foto (JPG/PNG/WEBP < 2 MB) berhasil; foto tampil di pojok kanan atas. Email tidak valid ditolak.
- [ ] Ketua kelas: kolom nama tidak bisa diubah. Guru: nama di data Guru ikut berubah.
- [ ] Ganti password: password lama salah ditolak; < 8 karakter, tanpa angka/huruf, `password`, atau sama dengan lama ditolak; konfirmasi beda ditolak.
- [ ] Setelah berhasil, banner password hilang; logout lalu login dengan password baru berhasil.
- [ ] Mencoba > 5 kali salah password lama dalam 10 menit -> diblokir sementara.

## 3. Pengaturan sekolah
- [ ] Simpan nama, NPSN (8 digit), alamat, kota, kepala sekolah, NIP -> tampil di kop *Laporan -> Cetak/PDF* dan tanda tangan.
- [ ] NPSN bukan 8 digit / NIP berisi huruf / timeout di luar 5-480 / scan limit di luar 10-600 ditolak.
- [ ] Unggah logo (PNG) -> tampil di kop laporan dan kartu QR; file bukan gambar atau > 2 MB ditolak; "Hapus logo" berfungsi dan file lama terhapus dari `uploads/school/`.

## 4. Tahun ajaran & semester
- [ ] Tambah tahun ajaran `2027/2028` dengan 4 tanggal -> muncul tidak aktif beserta dua semester.
- [ ] Nama tidak berformat `YYYY/YYYY+1`, tanggal selesai <= mulai, atau Genap mulai sebelum Ganjil selesai ditolak.
- [ ] Aktifkan tahun ajaran -> yang lain nonaktif; hanya satu semester aktif. "Aktifkan semester" memindahkan semester aktif.
- [ ] Hapus tahun ajaran aktif ditolak; hapus yang dipakai kelas ditolak; hapus yang kosong berhasil (semester ikut terhapus).

## 5. Hari libur
- [ ] Tambah satu tanggal; tambah rentang (mis. 2 minggu) dengan dan tanpa "Lewati Sabtu & Minggu" -> jumlah benar.
- [ ] Tanggal yang sudah ada dilewati dengan pesan; rentang > 62 hari ditolak; ubah dan hapus berfungsi; filter tahun berfungsi.
- [ ] Pada tanggal libur (set hari ini sebagai libur): scanner menolak ("sesi tidak berlaku"). Hapus libur -> scan normal lagi.

## 6. Backup & restore (Super Admin) - uji di database uji
- [ ] *Buat Backup* -> file muncul di daftar; unduh berhasil; file diawali `-- ABSENSI-QR-BACKUP v1` dan diakhiri `-- END-OF-BACKUP`.
- [ ] Backup dengan data berisi tanda kutip, titik koma, baris baru, huruf non-Latin (mis. nama siswa `O'Brien; Ä`) -> setelah restore datanya identik.
- [ ] Ubah/hapus beberapa data, lalu *Pulihkan* backup tadi (password + `RESTORE`) -> data kembali; Anda diminta login ulang; ada file `..._pre-restore.sql`.
- [ ] Salah password / salah ketikan konfirmasi -> ditolak, data tidak berubah.
- [ ] Restore dari unggahan: file `.sql` biasa (bukan hasil aplikasi), file terpotong, file yang disisipi perintah `DROP DATABASE` -> ditolak **sebelum** data berubah.
- [ ] Hapus file backup dari daftar berfungsi; nama file di luar pola tidak bisa diunduh/dihapus.
- [ ] *Unduh ZIP Upload* berisi foto/logo/bukti yang ada.
- [ ] Setelah restore, scan QR dan login semua peran tetap berfungsi.

## 7. Unduh QR (ZIP)
- [ ] Siswa -> pilih kelas -> *Unduh QR (ZIP)*: ZIP berisi `NIS_Nama.png` per siswa aktif + `daftar.csv` (terbuka benar di Excel, huruf Indonesia utuh).
- [ ] Pilihan "Semua kelas" menghasilkan subfolder per kelas. Guru hanya melihat kelas yang diampu.
- [ ] PNG hasil unduhan dapat dipindai oleh scanner dan dikenali sebagai siswa yang benar.
- [ ] Siswa tanpa token aktif otomatis dibuatkan token.

## 8. Panduan & tampilan
- [ ] Menu *Panduan* tampil untuk semua peran; bagian yang tidak relevan tersembunyi (guru tidak melihat Backup/Pengaturan).
- [ ] Tampilan nyaman di HP (sidebar buka-tutup), mode gelap, dan tabel dapat di-scroll.

## 9. Regresi Tahap 1-8 (singkat)
- [ ] CRUD jurusan, kelas, guru, siswa; import CSV; regenerasi QR membatalkan QR lama.
- [ ] Cetak kartu F4: 6 kartu/lembar, logo dan foto tampil.
- [ ] Scan: HADIR sebelum batas terlambat, TERLAMBAT sesudahnya, scan ganda ditolak, kelas lain ditolak, di luar jam ditolak.
- [ ] Absensi manual & koreksi tercatat di riwayat; tandai ALPA massal; bukti > 2 MB / tipe salah ditolak.
- [ ] Laporan: filter, ranking, Cetak/PDF, Export Excel; guru hanya melihat kelas yang diampu.
- [ ] Dashboard admin: kartu, grafik, scan terbaru muncul tanpa error JavaScript.

## 10. Sebelum dipakai sungguhan
- [ ] Semua password bawaan diganti; akun yang tidak dipakai dinonaktifkan.
- [ ] `APP_DEBUG` bernilai `false`; akses `/database/database.sql`, `/storage/`, dan `/README.md` dari browser ditolak (403).
- [ ] HTTPS aktif; backup pertama sudah diunduh dan disimpan di luar server; jadwal backup rutin ditetapkan.

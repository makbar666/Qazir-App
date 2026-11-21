# Kasir

Aplikasi POS mandiri berbasis Laravel 12. Proyek ini terpisah dari Tanda (`../sistem-point`) dan memakai database sendiri.

## Menjalankan

Prasyarat: PHP 8.2+, Composer, dan SQLite atau MySQL. Untuk lokal, `.env` bawaan memakai SQLite.

```powershell
composer install
php artisan migrate
php artisan pos:install
php artisan serve --port=8001
```

Buka `http://127.0.0.1:8001`, lalu masuk dengan email dan kata sandi owner yang dibuat lewat `pos:install`. Untuk MySQL, ubah `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` di `.env` sebelum migrasi.

## Alur yang tersedia

- Owner pertama membuat produk, harga, dan stok outlet dari halaman **Produk & stok**. Form tambah dibuka sebagai modal. Tombol **Hapus** menyembunyikan produk dari katalog dan kasir tanpa menghapus riwayat penjualan atau stok; produk dapat dipulihkan dari **Produk dihapus**.
- Kasir memilih produk di halaman **Kasir**, mengisi diskon dan pembayaran. QRIS Midtrans menunggu konfirmasi sebelum menjadi lunas. Harga dan nama produk disimpan sebagai snapshot item.
- Penjualan menyimpan kunci permintaan unik agar kiriman ulang tidak mengurangi stok lagi.
- Owner/manajer mencatat refund penuh. Untuk pembayaran manual, kasir memastikan uang dikembalikan sebelum mencatat; untuk QRIS dinamis Midtrans, POS mengajukan refund ke Midtrans dan menunggu konfirmasi bank.
- Struk dapat dicetak dari detail penjualan. Laporan harian menampilkan jumlah dan omzet penjualan aktif per metode bayar.

## QRIS dinamis Midtrans

1. Siapkan akun Midtrans dan ambil **Server Key Sandbox** dari dashboard Midtrans.
2. Jalankan `php artisan migrate`, masuk sebagai owner kasir, buka **QRIS Midtrans**, pilih Sandbox, isi Server Key, lalu aktifkan.
3. Saat memakai QRIS di kasir, sistem membuat QR khusus untuk nominal transaksi. Penjualan berstatus menunggu dan stok dipesan. Jangan serahkan barang sebelum status lunas.
4. Untuk server publik, pasang **Notification URL** yang ditampilkan di halaman pengaturan Midtrans. URL harus HTTPS dan dapat diakses Midtrans. Jalankan `php artisan schedule:work` atau `php artisan schedule:run` setiap menit untuk memeriksa transaksi pending sebagai cadangan webhook.
5. Untuk pengujian di localhost, gunakan tombol **Periksa status sekarang** pada halaman pembayaran setelah menyelesaikan pembayaran sandbox.

Status pembayaran QRIS juga dikirim lewat WebSocket Laravel Reverb. Untuk instalasi baru, jalankan `php artisan reverb:install`, lalu `npm install` dan `npm run build`. Saat pengembangan lokal, nyalakan `php artisan reverb:start --host=127.0.0.1 --port=8080` di terminal terpisah bersama `php artisan schedule:work`. Halaman pembayaran akan langsung membuka detail penjualan ketika status berubah menjadi lunas atau batal. Di localhost, pemeriksaan Midtrans berjalan setiap 10 detik karena webhook membutuhkan alamat publik; di server publik, webhook dapat mengirim perubahan segera. Jika WebSocket terputus, halaman memeriksa status lokal secara berkala.

Aplikasi memakai bundle CA dari [curl/Mozilla](https://curl.se/docs/caextract.html) di `resources/certs/cacert.pem` agar PHP di Windows dapat memverifikasi HTTPS Midtrans. Perbarui bundle ini secara berkala dari sumber tersebut. Jika server menggunakan bundle CA sendiri, set `MIDTRANS_CA_BUNDLE` ke path absolut bundle itu.

Notifikasi Midtrans diverifikasi menggunakan signature dan nominal. Notifikasi lunas mengubah penjualan menjadi lunas lalu mengirim poin ke Tanda jika member terhubung. Notifikasi kedaluwarsa atau batal melepaskan stok. Tombol **Batalkan transaksi** pada halaman pembayaran meminta Midtrans membatalkan QRIS yang sudah dibuat; jika Cancel API gagal karena server Midtrans, kasir mencoba Expire API. Stok dilepas setelah status akhir dipastikan. Jika keduanya belum berhasil, kasir menyembunyikan QR, mempertahankan stok sebagai pesanan tertunda, dan jadwal pemeriksaan akan mencoba lagi. Ubah ke Production hanya setelah menggunakan Server Key Production dan Notification URL publik.

### Refund QRIS Midtrans

Pada detail penjualan QRIS dinamis yang lunas, owner/manajer dapat mengajukan **refund penuh**. POS mengirim permintaan ke Refund API Midtrans dengan kunci refund yang tetap untuk mencegah pengajuan ganda. Status transaksi tetap **Lunas** selama menunggu konfirmasi bank; stok dan poin Tanda baru dipulihkan setelah notifikasi atau pemeriksaan status Midtrans memuat refund penuh dengan kunci, nominal, dan `bank_confirmed_at` yang cocok. Detail penjualan menyediakan tombol **Periksa status Midtrans** dan, bila permintaan awal gagal, tombol coba lagi menggunakan kunci yang sama. Scheduler `midtrans:reconcile` juga memeriksa refund yang belum selesai. Refund dari pembayaran QRIS manual, tunai, transfer, atau kartu tetap dicatat secara manual setelah uang dikembalikan.

Aktifkan kemampuan refund pada akun Midtrans bila Refund API mengembalikan penolakan. Refund biasanya hanya dapat diajukan setelah status Midtrans **Settlement**. Jika refund dilakukan langsung di dashboard Midtrans atau nilai/status tidak cocok, POS menahan perubahan stok dan poin untuk pemeriksaan manual. Uji alur ini di Sandbox sebelum mengaktifkannya di Production.

## Batas saat ini

Transaksi baru hanya menerima Tunai dan QRIS. QRIS ketika Midtrans tidak aktif dikonfirmasi manual oleh kasir. Riwayat transaksi Transfer dan Kartu yang sudah ada tetap dapat dibuka dan dilaporkan. Retur parsial, pajak, dan mode offline belum tersedia. Satu akun owner dan satu outlet pertama dibuat oleh installer. Akses data dibatasi ke bisnis dan outlet akun.

## Integrasi Tanda

1. Jalankan Tanda dan kasir, lalu migrasikan kedua database.
2. Masuk sebagai owner Tanda, buka **Koneksi POS**, buat token, lalu salin token yang tampil satu kali.
3. Masuk sebagai owner kasir, buka **Integrasi Tanda**, isi URL dan token, klik **Uji & hubungkan**.
4. Pasangkan setiap outlet kasir dengan outlet Tanda.
5. Di checkout kasir, cari nomor member Tanda sebelum mencatat penjualan. Penjualan lunas mengirim perolehan poin ke Tanda; refund penuh membaliknya.

Status sinkronisasi muncul pada detail penjualan dan halaman integrasi. Kegagalan dapat dicoba ulang dari layar tersebut. Untuk retry otomatis, jalankan `php artisan schedule:work` di terminal lain atau pasang `php artisan schedule:run` setiap menit di server. Token disimpan terenkripsi di database kasir. Gunakan HTTPS di deployment nyata.

Penukaran poin belum tersedia karena memerlukan reservasi poin sebelum pembayaran. Rancangan lanjutan ada di [`../sistem-point/docs/rancangan-integrasi-pos-tanda.md`](../sistem-point/docs/rancangan-integrasi-pos-tanda.md).

## Peringatan stok menipis dan email

Di menu **Stok menipis**, atur batas minimum dan target untuk setiap produk pada outlet aktif. Produk muncul saat stok berada pada atau di bawah batas. Kolom **Dalam pesanan** menghitung barang pada pesanan pembelian yang belum diterima; **Saran pesan** adalah target dikurangi stok dan barang dalam pesanan.

Untuk email, masukkan maksimal 10 alamat penerima per outlet dan aktifkan **Email harian**. Ringkasan dikirim sekali per hari per alamat bila ada produk menipis, pada pukul `STOCK_ALERT_DAILY_TIME` di zona `STOCK_ALERT_TIMEZONE` (bawaan 08:00 Asia/Jakarta). Jalankan `php artisan schedule:work` saat lokal, atau `php artisan schedule:run` tiap menit pada server. Periksa jadwal dengan `php artisan schedule:list`. Perintah `php artisan stock:alert-low --outlet=ID` tersedia untuk memproses satu outlet secara manual.

Mailer bawaan `MAIL_MAILER=log` hanya menulis email ke `storage/logs/laravel.log`. Agar terkirim ke penerima, atur `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, dan `MAIL_FROM_NAME` di `.env` sesuai penyedia email. Atur pula `APP_URL` ke alamat POS yang dapat dibuka penerima agar tautan dalam email benar. Setelah mengubah `.env` pada server yang memakai config cache, jalankan `php artisan config:clear` dan mulai ulang proses scheduler.

## Verifikasi

```powershell
php artisan test
```

# Finlite Gudang

Gudang memakai Finlite ID, anggota perusahaan, dan langganan produk dari platform.
Data operasional berada di koneksi `gudang` dengan migrasi `database/migrations/tenant_gudang`.
Perusahaan dapat menggunakan Gudang sendiri. Panduan arsitektur multi-produk tersedia di
[docs/pos-dan-multi-produk.md](pos-dan-multi-produk.md).

## Alur inventaris Fase 1

| Dokumen | Perubahan stok |
| --- | --- |
| Penerimaan langsung | Pemasok, barang dan lokasi dicatat bersama mutasi masuk. |
| PO atau penerimaan bertahap | Setiap kiriman aktual menghasilkan bukti penerimaan sendiri dengan UUID dan referensi ke dokumen induk. Sisa kuantitas tetap terbuka. |
| Surat jalan | Draf dapat dibatalkan. Penerbitan mengurangi saldo dan surat jalan dapat dicetak. |
| Putaway | Satu transaksi memindahkan barang antar lokasi dalam gudang yang sama. |
| Transfer | Kirim mengurangi stok asal dan membuka kuantitas dalam perjalanan. Terima menambah jumlah aktual di tujuan. Kekurangan dapat dikembalikan atau dikonfirmasi hilang. Kelebihan ditahan sampai pengembalian atau penerimaan dikonfirmasi. Menerima kelebihan juga mengurangi saldo asal dalam transaksi yang sama. Setiap keputusan mencatat pelaku dan alasan. |
| Penyesuaian | Alasan dan kuantitas bertanda disimpan di draf. Saldo berubah setelah persetujuan khusus. |

`StockLedger::move` menulis saldo dan mutasi di dalam transaksi dokumen dan log aktivitas.
Satuan dasar memiliki ketelitian 0,001. Pengeluaran memeriksa saldo melalui pembaruan
bersyarat pada database. Pemilik atau pengelola berizin dapat mengaktifkan saldo negatif.
Nomor berurutan per jenis dipakai di dokumen cetak. Integrasi Finlite Project kelak
merujuk perusahaan, produk, jenis dan UUID tetap. Kuantitas gudang dicatat di sini.
Pembebanan material proyek dan jurnal memerlukan transaksi dalam domain masing-masing.

## Akses dan penggunaan

Pengguna baru meminta kode lewat email dan memverifikasinya untuk memulai masa coba 14 hari.
Pengiriman kode menggunakan konfigurasi mail dan cache Laravel. Pengguna lama dapat memakai
email dan kata sandi Finlite. Masa coba hanya diberikan untuk riwayat langganan Gudang yang memenuhi syarat.
Selain izin fitur dalam matriks, akses lokasi staf mengikuti gudang yang diberikan pemilik.
Langganan habis membatasi akses menjadi hanya-baca.

| API di bawah `/api/gudang` | Kegunaan |
| --- | --- |
| `status`, `activate`, `bootstrap` | Aktivasi dan sesi tenant |
| `items`, `locations`, `warehouses`, `parties`, `members`, `settings` | Master data, penugasan gudang, pengaturan |
| `receipts`, `documents`, `documents/{id}/actions` | Penerimaan langsung, draf dan perubahan status |
| `stock`, `items/{id}/card`, `reports` | Saldo, kartu stok, laporan dengan filter serta ekspor Excel |
| `import/template`, `import` | Pratinjau dan komit baris Excel/CSV, termasuk saldo awal per lokasi dan tanggal |

Impor membaca berkas yang sama saat pratinjau dan saat disimpan. Setiap baris valid
menggunakan layanan barang dan buku stok. Baris salah mendapat pesan sendiri. Pengiriman
ulang berkas saldo awal yang sama untuk lokasi yang sama mengacu ke dokumen terdahulu.

## Pesanan keluar dan reservasi (Fase 3a)

Rute `GET/POST /api/gudang/outbound-orders` dan `POST /api/gudang/outbound-orders/{id}/actions`
memakai izin `gudang.issues.create`, akses gudang dan status langganan yang sudah berlaku.
Pesanan memesan kuantitas satuan dasar di satu lokasi simpan atau terima. Barang dengan
pelacakan batch belum didukung. Pesanan baru tidak membuat mutasi fisik.
`GET /api/gudang/stock` menampilkan stok fisik, dipesan dan tersedia per lokasi.

Pengiriman parsial atau penuh melepaskan jumlah yang dikirim dan menerbitkan surat jalan
dalam satu transaksi. Sisa tetap dipesan sampai dikirim atau dibatalkan dengan alasan.
Kunci simpan dan aksi mencegah pengulangan dengan payload yang sama. Semua pengurangan melalui
buku stok menghormati reservasi, termasuk ketika pengaturan mengizinkan saldo negatif.
Koreksi surat jalan mengembalikan stok fisik lewat dokumen balik. Riwayat pengiriman
tetap terlihat pada pesanan, beserta nomor koreksinya. Pesanan tidak dibuka ulang otomatis.
Pick list, verifikasi scan, packing dan reservasi batch menyusul.

## Verifikasi

GitHub Actions menjalankan suite Laravel, pengujian izin dan impor, serta uji tenant PostgreSQL
pada `tests/TenantPgsql/GudangLoadTest.php`. Fixture meliputi 5.000 SKU dan 3.500 mutasi
untuk tujuh tanggal dokumen. Dua proses bersaing mengeluarkan stok terakhir. Target baca
250 ms diukur lewat endpoint aplikasi pada runner. Repo `finlite-gudang` menjalankan
lint, build dan Playwright dengan backend pada revisi yang tercatat di workflow.
Validasi lapangan memerlukan operasi satu minggu dan pembandingan hasil hitung fisik.

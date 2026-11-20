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

## Reservasi, picking, packing dan putaway (Fase 3)

Rute `GET/POST /api/gudang/outbound-orders` dan `POST /api/gudang/outbound-orders/{id}/actions`
memakai izin `gudang.issues.create`, akses gudang dan status langganan yang sudah berlaku.
Pilih satu gudang lewat `warehouse_id`; API lama juga menerima `location_id` untuk satu
lokasi. Pesanan mengalokasikan kuantitas dasar ke lokasi simpan/terima. Input multi-satuan
disimpan bersama faktor konversi saat memesan. Untuk barang batch, alokasi memilih batch
aktif, belum kedaluwarsa, berdasarkan FEFO. Reservasi menahan saldo fisik di tingkat lokasi
dan batch. `GET /api/gudang/stock` menampilkan fisik, dipesan dan selisih keduanya per lokasi.

| Aksi | Syarat dan dampak |
| --- | --- |
| `pick` | Satu daftar per pesanan, urut lokasi. Kirim aktual setiap alokasi; jumlah positif memerlukan kode rak, SKU/barcode dan kode batch bila berlaku. Kekurangan perlu alasan, termasuk bila aktual nol. |
| `pack` | Sesi sudah diambil. Daftarkan maksimal 50 kemasan dengan berat gram dan catatan. Jumlah kemas mengikuti hasil ambil. |
| `ship` | Sesi sudah dikemas. Terbitkan satu surat jalan per lokasi asal, lepaskan reservasi dan kurangi fisik dalam satu transaksi. Batch diperiksa ulang saat posting. |
| `reset` | Batalkan sesi ambil/kemas dengan alasan setelah barang dikembalikan ke rak. Reservasi tetap ada. |
| `cancel` | Lepas semua sisa dengan alasan setelah sesi aktif dibatalkan. Pengiriman lama tetap tercatat. |

`GET /api/gudang/outbound-orders/{id}/runs/{run}/labels` menghasilkan PDF Code 128
untuk setiap kemasan yang sudah dikemas atau dikirim. Riwayat menghubungkan surat jalan
dan dokumen balik. Koreksi memulihkan fisik dengan dokumen balik dan tidak membuka
pesanan secara otomatis. Pengulangan permintaan dengan kunci dan isi sama tidak menggandakan
reservasi atau mutasi. Batch yang kemudian dikarantina atau kedaluwarsa tetap dipesan sampai
pesanan dibatalkan; pilih stok baru lewat pesanan baru setelah pembatalan.

`GET /api/gudang/putaway-suggestions` menerima lokasi asal dan daftar barang. Hasil dibatasi
ke lokasi simpan dalam gudang yang sama, diurutkan menurut jumlah SKU yang sudah ada,
kategori pilihan, dan slot SKU kosong. `PATCH /api/gudang/locations/{id}/putaway` mengatur
kategori dan batas jenis barang. Slot menghitung jumlah SKU berbeda dan merupakan saran,
bukan ukuran volume atau batas stok yang ditegakkan saat posting.

## Verifikasi

GitHub Actions menjalankan suite Laravel, pengujian izin dan impor, serta uji tenant PostgreSQL
pada `tests/TenantPgsql/GudangLoadTest.php`. Fixture meliputi 5.000 SKU dan 3.500 mutasi
untuk tujuh tanggal dokumen. Dua proses bersaing mengeluarkan stok terakhir. Target baca
250 ms diukur lewat endpoint aplikasi pada runner. Repo `finlite-gudang` menjalankan
lint, build dan Playwright dengan backend pada revisi yang tercatat di workflow.
Validasi lapangan memerlukan operasi satu minggu dan pembandingan hasil hitung fisik.

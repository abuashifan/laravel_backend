# Finlite Gudang: peta ringkas backend (Fase 0)

Rencana dan riset: `Finlite_knowladge/plans/ekosistem-aplikasi-pendukung/10-…` dan `11-…`.
Pola mengikuti `docs/pos-dan-multi-produk.md`; baca itu dulu untuk konsep tenant per produk.

## Status

Fase 1 dimulai dengan satu gudang per tenant, beberapa lokasi dan penerimaan tanpa PO.
Master barang memakai SKU unik dan satuan dasar. Penerimaan multi-baris mencatat dokumen,
mutasi dan saldo per barang/lokasi secara atomik, dalam kelipatan 0,001 satuan.
Kartu stok menampilkan dokumen asal, waktu dan lokasi. Aktor disimpan di dokumen.

Finlite Project dan produk Finlite lain kelak dapat merujuk dokumen dengan identitas
perusahaan, produk `gudang`, jenis dokumen dan `public_id` (UUID stabil). Nomor TRM
dipakai pada tampilan. Setiap produk membaca data melalui kontrak layanan yang
memverifikasi perusahaan dan izin. Kuantitas fisik dimiliki Gudang, valuasi dan jurnal
dimiliki Accounting. Pencatatan biaya atau pemakaian bahan proyek memerlukan transaksi
tersendiri. Penerimaan saat ini hanya mengubah stok fisik.

Endpoint tenant: GET/POST `/gudang/items` dan `/gudang/locations`, POST
`/gudang/receipts`, GET `/gudang/stock` dan GET `/gudang/items/{id}/card`.
Pengulangan penerimaan dengan request_key dan isi yang sama mengembalikan dokumen awal.

## Yang ditambahkan

| Hal | Lokasi |
| --- | --- |
| Produk `gudang` (suffix `_gudang`, koneksi `gudang`) | `config/tenant.php` `products`, `config/database.php` |
| Migrasi tenant Gudang | `database/migrations/tenant_gudang` |
| Paket `gudang` dan `gudang_free` (harga 0 = tempat; barang maks 100 untuk gratis) | migrasi `2026_11_15_000001_…`, `PlanSeeder`, `Plan::GUDANG_CODE` |
| Izin `gudang.*` | `config/permission_matrix.php` modul `gudang` |
| Modul | `app/Modules/Gudang` (`GET /gudang/status`, `POST /gudang/activate`, `GET /gudang/bootstrap`) |
| Admin: perpanjang/buka kunci langganan produk `gudang` | `ClientUserController` |
| Tes | `tests/Feature/Gudang/GudangFoundationTest.php` |

Aktivasi memakai jalur umum yang sama: `POST /companies` dengan `product=gudang`,
atau `POST /companies/{id}/products/gudang/activate`. Rute data Gudang memakai
`company.access:gudang` dan `product.subscription:gudang` (hanya-baca saat langganan habis).

## Belum ada (sengaja)

- Pendaftaran mandiri dan masa coba Gudang (Kasir punya `PosSignupController`); sementara paket diberikan admin.
- Harga dan batas final paket: menunggu keputusan pemilik produk.
- Pengeluaran, transfer, dan persetujuan penyesuaian stok.
- Panel admin client: tab Gudang.

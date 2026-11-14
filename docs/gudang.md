# Finlite Gudang: peta ringkas backend (Fase 0)

Rencana dan riset: `Finlite_knowladge/plans/ekosistem-aplikasi-pendukung/10-…` dan `11-…`.
Pola mengikuti `docs/pos-dan-multi-produk.md`; baca itu dulu untuk konsep tenant per produk.

## Status

Fase 0 (fondasi platform) selesai di sisi backend. **Belum ada fitur gudang**: tenant
Gudang baru berisi tabel `gudang_settings`. Barang, lokasi, dan buku stok datang di Fase 1.

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
- Aplikasi frontend Gudang (repositori terpisah, belum dibuat).
- Panel admin client: tab Gudang.

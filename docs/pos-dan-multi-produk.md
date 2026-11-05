# Multi-produk & Finlite POS — peta ringkas backend

Baca dokumen ini sebelum menyentuh tenant, langganan, atau modul POS. Rencana
per fase ada di `Finlite_knowladge/plans/ekosistem-aplikasi-pendukung/08-finlite-pos-rencana-fase.md`.
Perbarui dokumen ini setiap kali arsitektur di bawah berubah.

## Konsep

- **Perusahaan ≠ produk.** Satu perusahaan (`companies`) bisa punya tenant
  akuntansi, tenant POS, atau keduanya. Akun dan keanggotaan (`users`,
  `company_users`) satu untuk semua produk.
- **Tenant per produk**: `tenant_databases.product` (`accounting` | `pos`),
  unik per (company_id, product). Nama wadah: akuntansi `company_000001.sqlite`
  / schema `tenant_000001`; POS bersuffix `_pos`.
- **Daftar produk** dan propertinya: `config/tenant.php` → `products`
  (`label`, `suffix`, `migration_path`, `connection`).

## Lokasi kunci

| Hal | File |
| --- | --- |
| Model tenant + global scope akuntansi | `app/Shared/Models/TenantDatabase.php` (`forProduct()`, `allProducts()`) |
| Koneksi per produk (`tenant` / `pos`) | `app/Shared/Tenant/TenantConnectionManager.php::connectionFor()`, `config/database.php` |
| Aktivasi produk (buat wadah + migrasi) | `app/Shared/Tenant/TenantProductService.php` |
| Aturan siapa boleh mengaktifkan | `app/Modules/Companies/Services/ProductActivationService.php` |
| Migrasi tenant per produk | `app/Shared/Tenant/TenantMigrationService.php` (`migrationPathFor()`) |
| Middleware | `company.access` = akuntansi, `company.access:pos` = POS (`EnsureCompanyAccess`) |
| Langganan per produk | `app/Shared/Subscription/SubscriptionService.php` (argumen `$product`, bawaan `accounting`) |
| Kuota perusahaan per produk | `CompanyQuotaService::summaryForProduct()` |
| Modul POS | `app/Modules/Pos` (Models pakai koneksi `pos`), migrasi `database/migrations/tenant_pos` |
| Izin POS | `config/permission_matrix.php` modul `pos` |
| Test POS | `tests/Feature/Pos/`, helper `JournalTestCase::setUpPosTenant()` |

## Endpoint

- `GET/POST /companies` — `product` opsional saat membuat (`pos` = tanpa tenant
  akuntansi); payload memuat `products` (produk aktif).
- `POST /companies/select` — perusahaan tanpa tenant akuntansi → 422 `PRODUCT_NOT_ACTIVE`.
- `POST /companies/{id}/products/{product}/activate` — pemilik/akses penuh,
  pemilik perusahaan wajib langganan produk itu.
- `GET /pos/status`, `POST /pos/activate` — versi POS dari aktivasi.
- `/pos/*` lainnya — `company.access:pos` + izin `pos.*`.

## Jebakan

- `TenantDatabase::query()` hanya melihat tenant **akuntansi** (global scope).
  Untuk produk lain wajib `forProduct()` / `allProducts()`.
- `users.plan_id` = paket akuntansi saja. Langganan POS hanya di `subscriptions`
  (`product = pos`). Login masih dikunci oleh status langganan akuntansi.
- Model POS memakai koneksi `pos`; aturan validasi `exists`/`unique` memakai
  awalan `pos.` (mis. `exists:pos.pos_categories,id`).
- Paket `pos` dibuat migrasi `2026_11_06_000001` dengan harga 0 (tempat);
  helper test `activeSubscriptionFor()` hanya memilih paket akuntansi.
- Angka JSON: kolom float yang bulat dikirim sebagai `10`, bukan `10.0`.

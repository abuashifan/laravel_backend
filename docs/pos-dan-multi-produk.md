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
| Aturan siapa boleh mengaktifkan | `app/Shared/Tenant/ProductActivationService.php` |
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

## Uji E2E aplikasi kasir

`config('tenant.database_path')` bisa dipindah lewat env `TENANT_DATABASE_PATH`.
Repo `finlite_pos` (`npm run e2e`) memakainya untuk menaruh central dan tenant
sqlite di `finlite_pos/e2e/.data`, jadi berkas tenant dev di `database/tenants`
tidak bertabrakan. Seed E2E ada di `finlite_pos/e2e/backend/seed.php`.

## Fase 2: POS berdiri sendiri (PR #17, 6 November 2026)

- **Daftar mandiri** `POST /api/pos/register` (throttle 5/menit) → akun tanpa
  langganan + email verifikasi (`App\Mail\PosVerifyEmailMail`, tautan ke
  `POS_FRONTEND_URL/verify-email?token=`). Token = `Crypt` berisi uid, email,
  exp (48 jam) — `PosSignupController::verificationToken()`.
- `POST /api/pos/verify-email` (tanpa login) → `email_verified_at` + trial
  Kasir `Plan::POS_TRIAL_DAYS` (14) lewat `SubscriptionService::startTrial()`
  (sekali per produk, `metadata.trial`). Toko dibuat lewat `POST /companies`
  `product=pos`, yang butuh langganan POS → email palsu tidak bisa membuat tenant.
- `GET /api/pos/account`, `POST /api/pos/verify-email/resend`.
- **Hanya-baca**: middleware `product.subscription:pos`
  (`EnsureProductSubscription`) di grup rute POS. Pemilik (`companies.created_by`)
  tanpa langganan POS aktif/tenggang → tulis ditolak 402 `SUBSCRIPTION_INACTIVE`,
  GET tetap jalan. `bootstrap` mengirim `subscription` (+`read_only`) dan `is_owner`.
  Test yang memakai `setUpPosTenant()` otomatis diberi langganan POS.
- **Login** `app=pos` tidak dikunci status langganan akuntansi.
- **Pengguna toko** `/api/pos/team*` (izin `access.users.*`/`access.invitations.*`):
  templat peran di `config/pos.php` (`kasir`, `kepala_toko`). Undangan
  `company_invitations.product=pos` → `CompanyInvitationMail::acceptUrl()` ke
  `POS_FRONTEND_URL/invite/{token}`. Penerimaan memakai endpoint publik lama
  `/api/invitations/{token}` (register/accept).
- **Kuota pengguna** (`UserQuotaService::limitFor`) untuk toko ber-POS memakai
  `max_users` paket POS pemilik (ambil yang lebih besar bila juga akuntansi).
- **Reset sandi** `forgot-password` dengan `app=pos` → tautan ke aplikasi kasir.
- **Admin**: `GET /admin/clients?product=pos`, payload `pos_subscription`,
  `email_verified`; `renew`/`unlock` menerima `product`. UI admin belum dibuat.
- Env: `POS_FRONTEND_URL` (default `https://pos.finlite.my.id`). Verifikasi
  butuh pengiriman email (MAIL_*) jalan di Render.

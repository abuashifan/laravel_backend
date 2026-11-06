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
  `email_verified`; `renew`/`unlock` menerima `product`. UI: tab "Finlite
  Kasir" di detail client (react_frontend#10).
- Env: `POS_FRONTEND_URL` (default `https://pos.finlite.my.id`). Verifikasi
  butuh pengiriman email (MAIL_*) jalan di Render.

### Paket Kasir (PR #18)

- Paket `pos` = **Kasir** (3 pengguna, 1 toko, produk tanpa batas; dipakai
  trial) dan `pos_free` = **Kasir Gratis** (1 pengguna, 1 toko, 30 produk).
  Kolom `plans.max_products` (null = tanpa batas). **Harga masih 0**: ubah di
  `PlanSeeder` setelah pemilik produk memutuskan.
- `POST /api/pos/subscription/free`: pemilik mengambil Kasir Gratis sendiri
  (email terverifikasi, langganan Kasir tidak aktif). Langganan tahunan Rp 0,
  bisa diambil lagi.
- Batas produk: `PosPlanService::assertCanAddProduct()` di `storeProduct` → 422
  `PRODUCT_QUOTA_EXCEEDED`. Produk lama tidak dihapus saat turun paket; jatah
  pengguna juga hanya menahan undangan baru.
- `summaryFor()` memuat `plan_code`, `billing_cycle`, `limits`. `bootstrap`
  menambah `usage` dan `upgrade_url` (pemilik saja); `account` menambah
  `can_claim_free`, `upgrade_url`.
- `upgrade_url` = WhatsApp penjual dari env `POS_SALES_WHATSAPP` (628xxx);
  kosong = tombol tidak tampil. Pembayaran manual, admin memperpanjang.
- `/admin/plans?product=pos` memuat batas & harga; `/admin/clients/due-soon`
  satu baris per (client, produk) dengan `product`.

## Fase 3a: operasional toko (PR #19, 6 November 2026)

Migrasi tenant `tenant_pos/2026_11_10_000001_create_pos_operations_tables.php`.
Izin memakai kunci lama: baca `pos.products.view`, tulis `pos.stock.adjust`,
retur `pos.sales.delete` (setara pembatalan).

- **Retur** `POST /pos/sales/{id}/returns` (`PosReturnService`): sebagian/penuh,
  nilai = `line_total` proporsional x (total/subtotal) supaya diskon total ikut
  dibagi. `restock=false` = barang rusak (stok tidak kembali, HPP tetap biaya).
  `refund_method` bawaan mengikuti cara bayar (kasbon -> potong kasbon, tidak
  boleh melebihi sisa kasbon). Wajib shift terbuka milik pemroses; retur tunai
  mengurangi `expected_cash` shift itu (`cash_refunds`). Transaksi yang sudah
  diretur tidak bisa dibatalkan. `GET /pos/sales/{id}` memuat `returns` dan
  `returnable` (sisa per id baris). Laporan: `returns_total`, `net_sales`.
- **Stok masuk** `POST /pos/purchases` (`PosPurchaseService`): modal rata-rata
  tertimbang (stok minus dihitung 0; produk tanpa lacak stok = harga beli
  terakhir). Belum lunas wajib pemasok -> `pos_suppliers.payable_balance`;
  `POST /pos/suppliers/{id}/payments`. Uang pembelian **tidak** memengaruhi laci.
- **Stok opname** `POST /pos/stock-counts`: hanya produk lacak stok; mutasi
  tipe `count`, `difference_value` = selisih x modal.
- **Kunci idempoten** `pos_sales.idempotency_key` (unik): kunci sama -> 200
  dengan transaksi lama. Aplikasi kasir mengulang otomatis saat status 0/502-504.
- Tipe mutasi stok baru: `purchase`, `return`, `count`.
- Tes: `tests/Feature/Pos/PosOperationsTest.php`; E2E di `finlite_pos/e2e/kasir.spec.ts`.

## Fase 3b: harga, pajak, zona waktu (PR #20, 7 November 2026)

Migrasi tenant `tenant_pos/2026_11_12_000001_add_price_tiers_tax_rounding.php`.

- **Harga grosir** `pos_products.price_tiers` (JSON, maks 5, `min_quantity` > 1,
  dirapikan `PosPricingService::normalizeTiers`). Server memilih harga satuan
  menurut jumlah bila `unit_price` tidak dikirim (aplikasi kasir tidak mengirim).
- **Pajak & pembulatan** (`PosPricingService::totals`, pengaturan `tax_mode`
  none/exclusive/inclusive, `tax_rate`, `tax_name`, `rounding_unit` 0/100/500/1000,
  `rounding_mode` nearest/down/up). Dasar = subtotal baris - diskon total. Pajak
  dibulatkan ke rupiah; exclusive menambah total, inclusive hanya mencatat
  bagiannya. Pembulatan terakhir -> `rounding_amount`. Kolom `pos_sales`:
  `tax_amount`, `tax_inclusive`, `tax_rate`, `rounding_amount`; retur menyimpan
  `tax_amount` proporsional.
- Laporan: `net_sales` = Σ(total - pajak) - Σ(retur - pajak retur);
  `tax_total` terpisah. Pembulatan dihitung sebagai pendapatan.
- Rumus ditiru di `finlite_pos/src/lib/pricing.ts` untuk tampilan; **ubah
  keduanya bersamaan**.
- **Zona waktu toko** `PosClock` (pengaturan `timezone`, bawaan Asia/Jakarta):
  dipakai `PosController::dated()` (filter `date_from/date_to`), laporan
  (`by_day`, hari ini), dan `PosNumberService` (tanggal di nomor). Jangan pakai
  `now()->toDateString()` / `CarbonImmutable::parse($date)->startOfDay()` di
  modul POS: server UTC, transaksi 00.00-07.00 WIB jatuh ke kemarin.

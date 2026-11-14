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

## Fase 3c: impor Excel, foto produk (PR #21, 7 November 2026)

Migrasi tenant `tenant_pos/2026_11_14_000001_add_product_photos.php`.

- **Impor** `POST /pos/products/import` (`PosProductImportService`): XLSX/CSV,
  `commit=0` pratinjau tanpa menyimpan, `commit=1` simpan baris benar dan lewati
  yang salah (berkas dibaca ulang, tidak disimpan di disk). Kolom = konstanta
  `COLUMNS` (+ alias). Cocok ke produk lama lewat SKU → barcode → nama persis;
  sel kosong = nilai lama; stok produk lama tidak diubah (pakai stok masuk /
  opname); produk baru dengan kolom Stok = stok dilacak + mutasi "Stok awal
  (impor)". Kategori dibuat otomatis. Batas paket `max_products` dihitung per
  baris. CSV titik koma dikenali; angka teks lewat `parseNumber()` ("Rp 3.500",
  "3.500,50"), sel angka XLSX dipakai apa adanya. Maks 1000 baris.
- **Ekspor** `GET /pos/products/export` (`?template=1` = kosong), kolom sama.
- **Foto** `GET/POST/DELETE /pos/products/{id}/photo` (`PosProductPhotoService`).
  Disimpan sebagai data URL di database toko (`pos_product_photos.data`) karena
  disk kontainer Render hilang tiap deploy; gambar mini di
  `pos_products.photo_thumb` ikut daftar produk. Aplikasi kasir memperkecil
  foto (480px / 96px JPEG); server cek mime + `getimagesizefromstring`, maks
  300 KB / 24 KB, kuota total per toko `POS_PHOTO_QUOTA_MB` (bawaan 50).
- Scan barcode kamera murni di aplikasi kasir (BarcodeDetector, ZXing untuk iOS).

## Fase 4a: laporan rinci & dasbor (PR #22, 7 November 2026)

Migrasi tenant `tenant_pos/2026_11_16_000001_add_product_min_stock.php`
(`pos_products.min_stock` + indeks laporan).

- `PosReportTableService`: setiap laporan = `{title, columns[{key,label,type}],
  rows, totals}`; layar kasir merender tabel generik dan
  `GET /pos/reports/{type}/export` memakai `exportRows()` → XLSX. Tipe:
  `cashiers`, `shifts`, `products` (laba = baris setelah diskon baris, sebelum
  diskon nota, dikurangi retur; HPP retur yang kembali ke stok dikembalikan),
  `categories`, `hours` (jam toko, dikelompokkan di PHP per 2000 baris),
  `low-stock` (stok ≤ minimum atau habis ≤ 7 hari menurut rata-rata 14 hari;
  saran beli cukup 14 hari), `credit-aging` (FIFO: sisa kasbon = transaksi
  kasbon terbaru senilai saldo). Tambah tipe = tambah ke `TYPES` + `match`.
- `GET /pos/reports/dashboard`: hari ini vs kemarin, 7 hari, terlaris, jumlah
  stok menipis, kasbon beredar, shift buka.
- Filter `low_stock` produk kini `stock <= COALESCE(min_stock, 0)`; impor/ekspor
  punya kolom "Stok minimum".

## Fase 4b: log audit, batas wewenang, PIN kepala toko

Migrasi tenant `tenant_pos/2026_11_18_000001_create_pos_audit_and_controls.php`
(`pos_audit_logs`, `pos_member_limits`, `pos_supervisor_pins`) dan migrasi pusat
`2026_11_18_000002_sync_pos_control_permissions.php` (izin baru).

- **Log audit** `PosAuditService::record()` (aksi di `ACTIONS`). Ditulis di
  dalam transaksi aksi yang dicatat, jadi aksi yang batal tidak meninggalkan
  jejak. Tercatat: buka/tutup shift (dengan selisih laci), batal transaksi,
  retur, ubah harga jual/modal/grosir produk, ubah stok manual dan stok
  opname, diskon di atas batas, ganti/hapus PIN, PIN salah, ubah pengaturan
  toko dan batas diskon kasir. Baca: `GET /pos/audit-logs` (filter `action`,
  `user_id`, `search`, `date_from/date_to`) dan `/audit-logs/actions`, izin
  `pos.audit.view`. Tabel hanya ditambah, tidak ada rute ubah/hapus.
  **Belum tercatat:** perubahan harga lewat impor produk, stok masuk (punya
  dokumen sendiri), dan penyesuaian stok produk baru (stok awal).
- **Batas diskon** `PosSecurityService::discountLimit()`: izin
  `pos.discount.override` (kepala toko, pemilik) = tanpa batas; selain itu batas
  pribadi (`pos_member_limits`, diatur dari `PATCH /pos/team/members/{id}`
  `max_discount_percent`) mengalahkan batas toko (`max_discount_percent` di
  pengaturan; kosong = tanpa batas). Persentase = (potongan dari harga resmi
  + diskon baris + diskon nota) / nilai harga resmi. Harga yang diturunkan kasir
  lewat `unit_price` dihitung sebagai diskon juga. Melebihi batas = 422 di kunci
  `supervisor_pin`; kirim ulang dengan `supervisor_pin` benar untuk lolos
  (tercatat `discount.override`).
- **PIN kepala toko**: satu PIN per toko (4-8 angka), hash bcrypt di
  `pos_supervisor_pins` (sengaja bukan `pos_settings`, karena pengaturan dibaca
  semua pengguna). `PUT/DELETE /pos/security/pin` (izin `pos.settings.edit`;
  pemilik boleh ganti tanpa PIN lama). Lima kali salah = terkunci 5 menit.
  **`verifyPin()` wajib dipanggil di luar transaksi DB**, kalau tidak pencacah
  dan log salah-PIN ikut ter-rollback. Pengaturan dan bootstrap hanya
  menampilkan `supervisor_pin_set`.
- **Batal/retur oleh kasir**: rutenya terbuka untuk `pos.sales.delete` atau
  `pos.sales.create`; penjaganya `PosSecurityService::authorizeSensitive()`.
  Kepala toko/pemilik langsung jalan; kasir hanya kalau pengaturan
  `cashier_void_with_pin` = 1 (mati bawaan, butuh PIN sudah dibuat) dan
  `supervisor_pin` benar, selain itu 403. Log memberi tanda
  `approved_with_pin`. Menghapus PIN mematikan `cashier_void_with_pin`.
- Templat peran `kepala_toko` kini memuat `pos.audit.view` dan
  `pos.discount.override`. Anggota yang diundang sebelum 4b memegang set lama
  dan akan tampil "Khusus" sampai perannya disetel ulang.
- Tes: `tests/Feature/Pos/PosControlsTest.php`.

## Fase 4c: ketahanan beban (2.000 transaksi per hari)

Migrasi tenant `tenant_pos/2026_11_20_000001_add_load_indexes.php` dan
`2026_11_20_000002_create_pos_number_sequences.php`. Diukur di Postgres 16
dengan 30 hari x 2.000 transaksi (60.000 nota, sekitar 180.000 baris barang, dan
mutasi stok untuk produk berstok) lewat `tests/TenantPgsql/PosLoadTest.php`.

Sebelum dan sesudah (waktu satu permintaan, hangat):

| Permintaan | Sebelum | Sesudah |
| --- | --- | --- |
| Ringkasan 30 hari | 15,7 dtk | 0,24 dtk |
| Laporan per jam 30 hari | 20,2 dtk | 0,04 dtk |
| Dasbor pemilik | 5,3 dtk | 0,24 dtk |
| Ringkasan 7 hari | 3,6 dtk | 0,14 dtk |
| Daftar transaksi hari ini (100) | 0,58 dtk | 0,03 dtk |
| Laporan umur kasbon | 0,53 dtk | 0,04 dtk |
| Simpan transaksi (rata-rata) | 48 md | 23 md |

Penyebab dan perbaikan:

- **Pengelompokan di PHP.** `by_day` di ringkasan dan laporan per jam menarik
  setiap nota ke PHP (hidrasi model + Carbon, ~0,26 md per baris). Kini
  `GROUP BY` di database lewat `PosClock::localDateSql()` / `localHourSql()`
  (cabang `pgsql` dan `sqlite`; zona Indonesia tanpa DST, jadi cukup digeser
  menit). Tes batas hari WIB: `PosScalingTest` (SQLite) dan
  `PosLoadTest::test_postgres_groups_sales_by_the_store_day_and_hour`.
- **Indeks foreign key.** Postgres tidak mengindeks FK otomatis (MySQL iya).
  `pos_sale_lines.sale_id` dan `product_id` tidak punya indeks, jadi join
  laporan, hitungan baris per nota, dan hapus produk memindai seluruh tabel.
  Ditambah juga `pos_sales (customer_id, sold_at)`, FK retur, pelunasan kasbon,
  baris stok masuk dan opname.
- **Umur kasbon** memakai satu query (kursor) untuk semua pelanggan, bukan satu
  per pelanggan.
- **Zona waktu toko** dibaca sekali per instance `PosClock`; sebelumnya satu
  query pengaturan per baris di laporan shift dan umur kasbon.
- **Nomor dokumen bentrok.** Cara lama (`SELECT ... FOR UPDATE LIMIT 1` pada
  nomor terakhir) hanya mengunci satu baris dan tidak mengunci apa pun pada
  transaksi pertama hari itu. Uji bersamaan (8 permintaan serentak, backend 8
  worker, Postgres): 24 dari 120 transaksi gagal 500 di kolom nomor UNIQUE.
  Kini `PosNumberService` memakai penghitung atomik `pos_number_sequences`
  (satu baris per awalan per hari, dinaikkan dengan `UPDATE ... last_value + 1`
  yang mengunci barisnya sampai commit). Hasil: 120/120 dan 200/200 (keranjang
  dengan urutan produk berlawanan) tanpa gagal, tanpa nomor kembar, tanpa
  deadlock. Karena kunci penghitung diambil di awal transaksi, transaksi simpan
  mengantre rapi dan tidak saling mengunci baris stok. Nomor tidak bolong kalau
  transaksi batal (penghitung ikut rollback). Baris penghitung yang hilang
  diisi ulang dari nomor tertinggi yang tersimpan.

Menjalankan uji beban (butuh Postgres; suite lain dilewati tanpa URL):

```bash
TENANT_TEST_PGSQL_URL="postgresql://user:pass@127.0.0.1:5432/db" \
POS_LOAD_DAYS=30 POS_LOAD_PER_DAY=2000 POS_LOAD_BUDGET_MS=1500 \
vendor/bin/phpunit --testsuite=TenantPgsql --filter PosLoadTest
```

Tes gagal kalau satu permintaan baca melewati `POS_LOAD_BUDGET_MS` atau
rata-rata simpan transaksi melewatinya. Tabel waktu ditulis ke
`storage/logs/pos-load.txt`. Angka sandbox bukan Neon: sebelum rilis, jalankan
juga ke database Neon (URL direct) karena latensi jaringan ke database menambah
tiap query.

Yang belum diukur: Neon sungguhan, dan beban baca-tulis bersamaan dari banyak
perangkat selama jam ramai (uji bersamaan di atas hanya 8 penyimpan serentak).
`GET /pos/reports/summary` menjalankan beberapa query agregat per panggilan;
wajar di angka di atas, tetapi dasbor yang di-refresh otomatis per beberapa
detik dari banyak perangkat perlu cache bila kelak dibuat.

## Fase 5: jembatan Kasir -> Finlite Accounting

Migrasi tenant `tenant_pos/2026_11_22_000001_create_pos_bridge_tables.php`
(`pos_bridge_config`, `pos_bridge_dispatches`). Kode: `PosBridgeService`,
`PosBridgeJournalBuilder`, `PosBridgeController`; tes `tests/Feature/Pos/PosBridgeTest.php`.

**Prinsip:** arus SATU ARAH dan IDEMPOTEN. POS mengirim ringkasan; Akuntansi
tidak mengubah data POS. Toko hanya-Kasir tidak terpengaruh (`eligible=false`,
menu tidak tampil; `bootstrap.accounting_active`).

- **Satuan kiriman = satu shift yang sudah ditutup.** Shift tertutup tidak
  berubah (batal hanya selama shift terbuka; retur dan pelunasan kasbon
  tercatat di shift pemrosesnya), jadi aman jadi dasar jurnal. Satu jurnal
  sistem "Rekap Kasir" per shift, lewat `SystemJournalBuilder` (posted,
  `is_system_generated`, `source_type=pos_shift`, `source_module=pos`,
  `source_id`=id shift).
- **Isi jurnal** (`PosBridgeJournalBuilder`): debit Kas/Bank/QRIS/Piutang kasbon
  menurut cara bayar (uang muka kasbon = tunai); debit Diskon penjualan; kredit
  Pendapatan = subtotal + pembulatan - pajak yang sudah termasuk harga; kredit
  Pajak keluaran; retur di shift (debit Retur penjualan dan Pajak, kredit
  Kas/Bank/QRIS/Piutang menurut cara kembali uang); pelunasan kasbon (debit
  Kas/Bank/QRIS, kredit Piutang); opsional `post_cogs`: debit HPP, kredit
  Persediaan memakai `unit_cost` saat jual (retur yang kembali ke stok
  mengurangi). Akun yang sama dijumlahkan bersih. Tidak seimbang -> tidak
  dikirim (gagal).
- **Idempotensi dua lapis:** unik `(kind, source_id)` di `pos_bridge_dispatches`,
  dan sebelum membuat jurnal dicari jurnal bersumber shift itu di akuntansi
  (termasuk yang di-void/dihapus lunak). Putus dan sambung ulang, hapus baris
  kiriman, atau kirim ulang tidak menggandakan jurnal.
- **Kegagalan tidak pernah menggagalkan aksi kasir.** `onShiftClosed()` memakai
  try/catch; menutup shift tetap 200. Status kiriman: `pending`, `sent`,
  `failed` (pesan galat, mis. akun belum dipetakan), `held` (periode atau tahun
  fiskal terkunci), `skipped` (shift tanpa transaksi, tidak membuat jurnal kosong).
  Kirim ulang: `POST /pos/bridge/dispatches/{id}/retry` atau `/bridge/sync`.
- **Yang dicek sebelum kirim hanya kunci periode/tahun fiskal**
  (`PeriodLockService::isDateReadOnly`, `AnnualClosingGateService`), BUKAN seluruh
  `TransactionDateGuardService`. Aturan tanggal mundur/masa depan di sana untuk
  input manual dan memakai tanggal server (UTC): shift yang ditutup 00.00-07.00
  WIB sudah "besok" menurut server dan akan ditolak "Future transaction is not
  allowed" -- terbukti di tes. Tanggal jurnal = tanggal tutup shift menurut
  jam toko (`PosClock`). Regresi dikunci `test_late_night_shift_is_not_held_as_a_future_transaction`.
- **Pengaturan** `pos_bridge_config` (satu baris): `enabled`, `start_date`
  (shift ditutup pada/setelah tanggal ini ikut, termasuk yang lama),
  `post_cogs`, `account_map` (kunci -> id akun di tenant AKUNTANSI; wajib:
  cash, bank, qris, receivable, revenue; discount/sales_return jatuh ke revenue
  bila kosong; tax/cogs/inventory baru wajib saat nilainya muncul). Akun harus
  aktif, terbawah, dan jenisnya sesuai (`ALLOWED_TYPES`).
- **Endpoint** (izin `pos.settings.view` baca, `pos.settings.edit` tulis):
  `GET /pos/bridge`, `GET /pos/bridge/accounts` (akun + usulan dari kode
  template, mis. 1100/1110/1120/4100/2120), `PUT /pos/bridge`,
  `GET /pos/bridge/dispatches?status=`, `POST /pos/bridge/sync`,
  `POST /pos/bridge/dispatches/{id}/retry`. Menyalakan jembatan langsung
  mengantre dan mengirim shift lama (maks 100 per panggilan; sisanya lewat
  sync). Perubahan tercatat di log audit (`settings.update`).
- Memutus jembatan menghentikan kiriman baru; jurnal yang sudah terkirim tetap.
- Koneksi akuntansi (`tenant`) dibuka dari dalam request POS lewat
  `TenantConnectionManager::connect()` (dua koneksi hidup berdampingan).

**Belum dikerjakan (Fase 5 berikutnya):** stok masuk dan pembayaran pemasok
sebagai jurnal (butuh akun Hutang pemasok), sinkron produk POS <-> Akuntansi,
dan tautan dari jurnal Rekap Kasir ke layar shift di aplikasi akuntansi
(saat ini jurnal bisa disaring lewat Jenis Jurnal -> Rekap Kasir).

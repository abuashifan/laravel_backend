# Memindahkan tenant database ke Postgres

Catatan operasional untuk siapa pun yang men-deploy aplikasi ini.

> **Status: selesai dan terverifikasi (2026-09-21).** Perusahaan dibuat dengan
> `driver=pgsql`, datanya bertahan melewati redeploy Render. Masalah kehilangan
> data tiap deploy sudah tidak ada.

## Dua hal yang menggagalkan cutover pertama

Keduanya memakan waktu berjam-jam untuk ditemukan, jadi dicatat lebih dulu di sini.

**1. Region database harus sama dengan region aplikasi.** Project Neon pertama
dibuat di `us-east-2` (Ohio) sementara Render berjalan di Oregon (`us-west1`).
Tiap perintah SQL menempuh ~50–70 ms pulang-pergi, dan provisioning menjalankan
ratusan perintah — pembuatan perusahaan menggantung lebih dari dua menit lalu
diputus gateway. Setelah project Neon dipindah ke `us-west-2` (Oregon), waktunya
turun ke hitungan detik.

Ini bukan penyetelan halus. Untuk aplikasi yang membangun 70 tabel setiap kali
perusahaan dibuat, jarak antara aplikasi dan database menentukan bisa atau tidak.

**2. `$table->enum(...)->change()` tidak jalan di Postgres.** Lihat
`2026_08_14_000003_add_versioning_to_budget_submissions_table.php`. Di Postgres
`enum()` adalah `varchar` + CHECK constraint terpisah, dan Laravel menempelkan
`check (...)` ke `ALTER COLUMN ... TYPE` — sintaks yang tidak sah, gagal dengan
`SQLSTATE 42601`. Di SQLite gejalanya tidak pernah muncul karena `change()` di
sana membangun ulang seluruh tabel.

**Aturannya: jangan pernah `->change()` sebuah kolom enum di migration tenant.**
Ganti daftar nilainya lewat helper yang sadar driver, seperti `setStatusValues()`
di migration tersebut.

## Kenapa

Tenant database awalnya satu berkas SQLite per perusahaan di
`database/tenants/`. Folder itu bagian dari image Docker — `Dockerfile` menyalin
seluruh repo ke `/var/www/html` — dan Render membangun ulang image setiap deploy.
Akibatnya **seluruh data tenant terhapus setiap kali kode di-push**, dan juga
setiap kali container restart setelah idle. Render free tier tidak menyediakan
persistent disk, jadi ini tidak bisa diperbaiki dengan konfigurasi.

Postgres tinggal di layanan terpisah (Neon), jadi deploy tidak menyentuhnya.

## Bentuknya

Satu schema per perusahaan, **di dalam database central yang sama**:

| | SQLite | Postgres |
|---|---|---|
| `tenant_databases.database_name` | `company_000001.sqlite` | `tenant_000001` |
| `tenant_databases.database_path` | path absolut berkas | nama schema (sama) |
| `tenant_databases.driver` | `sqlite` | `pgsql` |

Pemisahnya `search_path`, disetel di `PostgresTenantStorage::connect()`.

`search_path` **sengaja tidak menyertakan `public`**. Tabel central (users,
companies, plans, subscriptions) tinggal di sana; kalau `public` ikut dicari,
tabel tenant yang belum ada akan diam-diam jatuh ke tabel central bernama sama —
kebocoran lintas tenant tanpa error apa pun. Query gagal jauh lebih baik daripada
diam-diam menjawab dengan data yang salah. Ini dikunci di
`PostgresTenantIsolationTest`.

## Langkah cutover di Render

1. Tambah environment variable:

   ```
   TENANT_DRIVER=pgsql
   ```

   Kosongkan `TENANT_PGSQL_CONNECTION` — defaultnya mengikuti koneksi central,
   dan itu yang benar karena schema tenant memang dititipkan di database yang
   sama.

2. Deploy. Tidak ada migration central baru; kolom `database_path` yang sudah
   `unique()` dan NOT NULL cukup menampung nama schema.

   ⚠️ **Menyimpan environment variable di Render memicu restart, BUKAN build
   ulang.** Service start lagi memakai image yang sudah ada dan tidak menarik
   kode baru dari GitHub. Kode lama tidak mengenal `TENANT_DRIVER` sama sekali,
   sehingga variabelnya diabaikan total dan tenant tetap dibuat sebagai SQLite —
   tanpa error apa pun yang menjelaskan kenapa. Setelah memasang env var, selalu
   **Manual Deploy → Deploy latest commit**.

   Dan jangan menyimpan env var atau men-deploy **selagi ada perusahaan sedang
   dibuat**. Restart di tengah provisioning membunuh prosesnya sebelum rollback
   sempat jalan, dan meninggalkan perusahaan setengah jadi yang memakan kuota
   tapi tidak bisa dipakai.

3. Perusahaan **baru** langsung mendapat schema Postgres.

4. Perusahaan **lama** barisnya masih bertanda `driver=sqlite` dan berkasnya
   sudah lenyap. Di admin panel, tab **Paket & Langganan → Pemakaian
   Penyimpanan**, perusahaan itu muncul dengan badge "Database hilang". Klik
   **Buat Ulang Database**.

   Tombol itu sekaligus jalur pindah: `TenantRepairService` selalu membangun
   ulang memakai driver yang berlaku sekarang, jadi baris `sqlite` bangkit
   sebagai schema Postgres dan barisnya ikut ditulis ulang. Datanya tidak
   dipulihkan — memang sudah hilang sejak deploy sebelumnya.

## Kalau salah setel

`TENANT_DRIVER=pgsql` sementara koneksi central ternyata bukan Postgres akan
**gagal keras** saat provisioning, dengan pesan yang menyebut koneksi dan driver
yang ditemukan. Ini disengaja: tanpa pemeriksaan itu, config koneksi tetap
disalin beserta `search_path` yang tidak dikenal drivernya, dan kegagalannya baru
muncul jauh kemudian sebagai error SQL yang menyesatkan.

## Menjalankan tes

Mayoritas tes tetap berjalan di atas tenant SQLite — cepat dan tidak butuh
server. Yang tidak bisa dibuktikan suite itu adalah hal yang memang berbeda antar
database: sintaks DDL, perilaku `search_path`, dan fungsi tanggal. Itu ditutup
suite terpisah:

```bash
TENANT_TEST_PGSQL_URL="postgresql://..." vendor/bin/phpunit --testsuite=TenantPgsql
```

Pakai connection string **direct**, bukan yang pooled — migration gagal di
PgBouncer dengan `SQLSTATE[25P02]`.

Tanpa variabel itu seluruh suite tersebut di-skip, sehingga `php artisan test` di
mesin tanpa Postgres tetap hijau.

**Jalankan suite ini setiap kali menambah migration tenant.** Bug `enum()->change()`
di atas lolos justru karena suite ini belum pernah dijalankan terhadap Postgres
nyata — dan ditemukan dengan cara paling mahal: satu per satu lewat UI
production, tiap putaran butuh deploy. Satu kali menjalankan suite ini
menampilkan seluruh migration yang tidak kompatibel sekaligus, beserta stack
trace-nya.

Amannya: suite hanya membuat schema bernama `tenant_test_*` dan membuangnya
sendiri di `tearDown()`. Tabel central di schema `public` tidak disentuh, karena
test memakai SQLite in-memory untuk data central. Untuk terpisah penuh, pakai
**branch** Neon (gratis) dan connection string branch itu.

## Menambah driver lain

Implementasikan `App\Shared\Tenant\Storage\TenantStorage`, daftarkan di
`TenantStorageManager::driver()`. Tidak ada kode di luar folder `Storage/` yang
perlu tahu bentuk penyimpanannya — itu sebabnya lapisan ini ada.

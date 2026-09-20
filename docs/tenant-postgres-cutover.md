# Memindahkan tenant database ke Postgres

Catatan operasional untuk siapa pun yang men-deploy aplikasi ini.

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

## Menambah driver lain

Implementasikan `App\Shared\Tenant\Storage\TenantStorage`, daftarkan di
`TenantStorageManager::driver()`. Tidak ada kode di luar folder `Storage/` yang
perlu tahu bentuk penyimpanannya — itu sebabnya lapisan ini ada.

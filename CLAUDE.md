# Instruksi Backend untuk Claude

Baca `AGENTS.md` di folder ini sebelum bekerja. Status project dan data pada
`AGENTS.md` berlaku juga untuk Claude: Finlite masih development, domain aplikasi
dipakai untuk testing, belum ada pengguna produksi, dan seluruh transaksi saat
ini adalah data uji. Jangan menambahkan syarat kompatibilitas izin lama karena
asumsi pengguna produksi saat mengerjakan rencana hak akses per pengguna.

## Menjalankan tes: pakai GitHub Actions lewat `gh`

Tes penuh dijalankan di GitHub Actions, bukan lokal. `php artisan test` penuh di
sandbox butuh ±9 menit; workflow `Tests` di CI berjalan paralel (±4-6 menit) dan
itulah syarat deploy Render ("After CI Checks Pass").

- **Lokal hanya tes sempit** untuk iterasi: `php artisan test tests/Feature/Pos`
  atau `--filter <nama>`. Jangan menjalankan suite penuh lokal.
- **Tes penuh = CI.** Commit, push branch kerja, lalu pantau lewat `gh`:
  - `gh run list -R abuashifan/laravel_backend -b <branch> -L 3`
  - `gh run watch <run-id> -R abuashifan/laravel_backend --exit-status`
  - `gh run view <run-id> -R abuashifan/laravel_backend --log-failed` (bila gagal)
  - `gh api "repos/abuashifan/laravel_backend/actions/runs?branch=<branch>"`
- **Workflow `Tests` hanya terpicu oleh `pull_request` dan push ke `main`**
  (tidak ada `workflow_dispatch`). Branch kerja tanpa PR tidak punya run CI, jadi
  untuk menjalankan tes penuh buka PR (draft boleh) dari branch kerja.
- **Jangan pakai `gh pr ...`** (list, checks, create, merge): perintah itu memakai
  GraphQL dan ditolak di sesi Claude Code (HTTP 403). Untuk PR dan statusnya pakai
  tool GitHub MCP (`create_pull_request`, `pull_request_read` dengan
  `get_check_runs`) atau `gh api` REST.
- **Jangan menyatakan tes hijau** sebelum run untuk commit TERBARU (cocokkan
  `head_sha`) berstatus `completed` dan `success`. Run lama dari commit
  sebelumnya tidak berlaku. Kalau gagal, baca `--log-failed`, perbaiki, push
  ulang. Kalau `gh` gagal otentikasi, pakai tool GitHub MCP atau laporkan; jangan
  diam-diam kembali menjalankan suite penuh lokal.
- **Yang tetap lokal:** suite `TenantPgsql` (termasuk uji beban `PosLoadTest`)
  butuh Postgres dan `TENANT_TEST_PGSQL_URL`, tidak dijalankan di job utama.
  Jalankan lokal bila perubahan menyentuh jalur itu. Aturan ini hanya untuk
  backend ini; `finlite_pos` (E2E butuh PHP dan repo backend, tanpa CI) dan
  `react_frontend` (build ±2 detik) tetap diuji lokal.

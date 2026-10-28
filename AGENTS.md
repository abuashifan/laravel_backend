# Laravel Backend Project Rules

This is the Laravel backend project for the multi-tenant accounting ERP.

## Status Project dan Data (keputusan pemilik produk, 2026-10-28)

Finlite masih dalam tahap development. Aplikasi di `app.finlite.my.id` dipakai
untuk testing; belum ada pengguna produksi, dan seluruh transaksi saat ini
adalah data uji. Backend di Render, frontend di Vercel, dan Render menjalankan
migrasi baru secara otomatis saat deploy.

Untuk implementasi rencana hak akses per pengguna di root workspace
(`RANCANGAN_HAK_AKSES.md` dan `RENCANA_IMPLEMENTASI_HAK_AKSES.md`), jangan
mengasumsikan ada pengguna atau transaksi produksi yang memerlukan kompatibilitas
izin lama. Kunci izin, role, dan struktur lama boleh diganti atau dihapus sesuai
rencana tanpa membuat masa transisi khusus demi data uji. Perbarui rute,
migrasi, frontend, dan tes yang terkait secara konsisten. Tetap pertahankan
isolasi tenant, penegakan izin, serta aturan akuntansi dan verifikasi yang
memang dibutuhkan aplikasi.

## RTK Token Saving Rules

Use RTK for file inspection, git status, diffs, search results, logs, migrations, seeders, routes, controllers, models, services, tests, and artisan output.

Prefer:
- `rtk ls .`
- `rtk read path/to/file`
- `rtk grep "Route::" routes`
- `rtk grep "class .*Controller" app/Http/Controllers`
- `rtk grep "Schema::create" database/migrations`
- `rtk git status`
- `rtk git diff`

Do not dump huge Laravel logs, vendor folders, storage logs, cache folders, or full migration/controller trees into context.
Summarize first, then inspect only relevant files.

## Backend Guardrails

Preserve existing API contracts, tenant middleware, permissions, posting rules, journal immutability, and rollback/void behavior, except for intentional contract and permission changes in the approved task.
Do not refactor unrelated modules unless the task explicitly requires it.
After backend changes, run the narrowest relevant verification command available.

## Backend Docs Reading Order

Before backend audit, module planning, route work, migration work, or feature-gap analysis, read backend-local docs first:

1. `docs/backend-directory-tree.md`
2. `docs/backend-modular-monolith-plan.md`
3. `docs/backend-missing-modules-audit.md`

For fixed asset, opening balance, setup wizard, or period-end work, also read:

4. `docs/accounting-setup-and-fixed-assets-context.md`
5. The relevant file under `docs/implementation_plans/`, when it exists.

Use `/workspace/laravel_backend/docs/` as the source of truth for backend planning notes. Do not use `/workspace/docs` or `/workspace/frontend/docs` for backend gap status unless the user explicitly asks for historical/frontend context.

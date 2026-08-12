<?php

namespace Database\Seeders;

use App\Shared\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Kunci fitur yang gerbangnya dibaca `PlanPermissionResolver` lewat
     * `config/plan_features.php` — enam kunci yang memetakan ke izin, plus
     * `transaction_approval` yang tidak lewat corong permission (ia menggerbangi
     * NILAI pengaturan `transaction_workflow_mode`, dicek langsung di
     * `UpdateCompanyAccountingSettingRequest`). Peta lengkap dan alasannya ada di
     * `Finlite_knowladge/plans/subscription-tiers/phase-2-peta-tier-dan-peluncuran.md`.
     */
    private const PRO_FEATURES = ['multi_warehouse', 'audit_trail', 'advanced_reports', 'transaction_approval', 'transaction_import'];

    private const ENTERPRISE_FEATURES = [
        'multi_warehouse', 'audit_trail', 'advanced_reports', 'transaction_approval',
        'budgeting', 'dimensions', 'user_permission', 'transaction_import',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Dinonaktifkan sampai ada modal menanggung biaya user gratis (keputusan
        // pemilik produk 2026-08-11). Barisnya TIDAK dihapus: ia tetap nilai
        // cadangan `CompanyQuotaService::DEFAULT_PLAN_CODE` untuk client yang
        // belum diberi paket, dan bisa diaktifkan lagi kalau dipakai promosi.
        Plan::updateOrCreate(
            ['code' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Paket gratis untuk testing dan usaha kecil.',
                'max_users' => 1,
                'max_companies' => 1,
                'max_transactions_per_month' => 100,
                'storage_quota_mb' => 500,
                'import_retention_days' => 30,
                'can_use_sales' => false,
                'can_use_purchases' => false,
                'can_use_inventory' => false,
                'can_export_reports' => false,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'status' => 'inactive',
                'features' => [
                    'basic_accounting',
                    'journal_entries',
                    'basic_reports',
                ],
            ]
        );

        // Tidak menyertakan enam kunci gerbang: pembukuan penuh, persediaan satu
        // gudang, dan aktiva tetap TIDAK digerbangi (lihat "Aktiva tetap TIDAK
        // digerbangi" di dokumen Fase 2) — dicabut atas keberatan pemilik produk
        // karena tanpanya penyusutan tidak tercatat dan laporan Basic jadi salah.
        Plan::updateOrCreate(
            ['code' => 'basic'],
            [
                'name' => 'Basic',
                'description' => 'Paket dasar untuk UMKM: pembukuan penuh, satu gudang, aktiva tetap.',
                'max_users' => 3,
                'max_companies' => 1,
                'max_transactions_per_month' => 1000,
                'storage_quota_mb' => 1024,
                'import_retention_days' => 30,
                'can_use_sales' => true,
                'can_use_purchases' => true,
                'can_use_inventory' => true,
                'can_export_reports' => true,
                'monthly_price' => 99000,
                'yearly_price' => 990000,
                'status' => 'active',
                'features' => [
                    'basic_accounting',
                    'sales',
                    'purchases',
                    'inventory',
                    'reports_export',
                    'fixed_assets',
                ],
            ]
        );

        Plan::updateOrCreate(
            ['code' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'Untuk UMKM berkembang: + multi-gudang, jejak audit, laporan tersimpan, banding multi-periode, alur persetujuan.',
                'max_users' => 5,
                'max_companies' => 3,
                'max_transactions_per_month' => null,
                'storage_quota_mb' => 2048,
                'import_retention_days' => 30,
                'can_use_sales' => true,
                'can_use_purchases' => true,
                'can_use_inventory' => true,
                'can_export_reports' => true,
                'monthly_price' => 199000,
                'yearly_price' => 1990000,
                'status' => 'active',
                'features' => array_merge([
                    'basic_accounting',
                    'sales',
                    'purchases',
                    'inventory',
                    'reports_export',
                    'fixed_assets',
                    'multi_user',
                ], self::PRO_FEATURES),
            ]
        );

        // Harga belum ditentukan pemilik produk, jadi 0 — kolomnya tidak
        // nullable dan angka ini tidak dibaca kode mana pun.
        Plan::updateOrCreate(
            ['code' => 'enterprise'],
            [
                'name' => 'Enterprise',
                'description' => 'Untuk perusahaan dengan beberapa unit usaha: + anggaran, dimensi departemen & proyek, role kustom.',
                'max_users' => 10,
                'max_companies' => 5,
                'max_transactions_per_month' => null,
                'storage_quota_mb' => 5120,
                'import_retention_days' => 90,
                'can_use_sales' => true,
                'can_use_purchases' => true,
                'can_use_inventory' => true,
                'can_export_reports' => true,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'status' => 'active',
                'features' => array_merge([
                    'basic_accounting',
                    'sales',
                    'purchases',
                    'inventory',
                    'reports_export',
                    'fixed_assets',
                    'multi_user',
                ], self::ENTERPRISE_FEATURES),
            ]
        );

        // Tier khusus: jumlah perusahaannya tidak ditentukan paket, melainkan
        // diisi per client lewat `users.company_quota`. `max_companies` di sini
        // hanya nilai cadangan kalau kuota client belum diisi. Fiturnya sengaja
        // SAMA dengan Enterprise (keputusan pemilik produk 2026-08-11) — Custom
        // yang fiturnya dipilih per client adalah perubahan besar tersendiri,
        // menunggu permintaan nyata.
        Plan::updateOrCreate(
            ['code' => Plan::CUSTOM_CODE],
            [
                'name' => 'Custom',
                'description' => 'Kuota perusahaan ditentukan per client. Fitur sama dengan Enterprise.',
                'max_users' => 10,
                'max_companies' => 1,
                'max_transactions_per_month' => null,
                // Cadangan kalau kuota client belum diisi — nilai sungguhan
                // dibaca dari `users.storage_quota_mb`/`import_retention_days`,
                // sama seperti pola company_quota/user_quota.
                'storage_quota_mb' => 5120,
                'import_retention_days' => 90,
                'can_use_sales' => true,
                'can_use_purchases' => true,
                'can_use_inventory' => true,
                'can_export_reports' => true,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'status' => 'active',
                'features' => array_merge([
                    'basic_accounting',
                    'sales',
                    'purchases',
                    'inventory',
                    'reports_export',
                    'fixed_assets',
                    'multi_user',
                ], self::ENTERPRISE_FEATURES),
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::updateOrCreate(
            ['code' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Paket gratis untuk testing dan usaha kecil.',
                'max_users' => 1,
                'max_companies' => 1,
                'max_transactions_per_month' => 100,
                'can_use_sales' => false,
                'can_use_purchases' => false,
                'can_use_inventory' => false,
                'can_export_reports' => false,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'status' => 'active',
                'features' => [
                    'basic_accounting',
                    'journal_entries',
                    'basic_reports',
                ],
            ]
        );

        Plan::updateOrCreate(
            ['code' => 'basic'],
            [
                'name' => 'Basic',
                'description' => 'Paket dasar untuk UMKM.',
                'max_users' => 3,
                'max_companies' => 1,
                'max_transactions_per_month' => 1000,
                'can_use_sales' => true,
                'can_use_purchases' => true,
                'can_use_inventory' => false,
                'can_export_reports' => true,
                'monthly_price' => 99000,
                'yearly_price' => 990000,
                'status' => 'active',
                'features' => [
                    'basic_accounting',
                    'sales',
                    'purchases',
                    'reports_export',
                ],
            ]
        );

        Plan::updateOrCreate(
            ['code' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'Paket lengkap dengan inventory.',
                'max_users' => 10,
                'max_companies' => 3,
                'max_transactions_per_month' => null,
                'can_use_sales' => true,
                'can_use_purchases' => true,
                'can_use_inventory' => true,
                'can_export_reports' => true,
                'monthly_price' => 199000,
                'yearly_price' => 1990000,
                'status' => 'active',
                'features' => [
                    'basic_accounting',
                    'sales',
                    'purchases',
                    'inventory',
                    'reports_export',
                    'multi_user',
                ],
            ]
        );
    }
}


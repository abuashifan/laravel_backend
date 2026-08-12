<?php

namespace Database\Seeders;

use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class DemoCentralSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $user = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin Demo',
                'password' => Hash::make('password'),
                'status' => 'active',
            ]
        );

        $company1 = Company::updateOrCreate(
            ['slug' => 'pt-maju-jaya'],
            [
                'name' => 'PT Maju Jaya',
                'legal_name' => 'PT Maju Jaya',
                'code' => 'CMP-000001',
                'email' => 'admin@majujaya.test',
                'phone' => '081234567890',
                'address' => 'Jl. Demo No. 1',
                'city' => 'Sukabumi',
                'province' => 'Jawa Barat',
                'country' => 'Indonesia',
                'status' => 'trial',
                'created_by' => $user->id,
            ]
        );

        $company2 = Company::updateOrCreate(
            ['slug' => 'cv-sumber-rejeki'],
            [
                'name' => 'CV Sumber Rejeki',
                'legal_name' => 'CV Sumber Rejeki',
                'code' => 'CMP-000002',
                'email' => 'admin@sumberrejeki.test',
                'phone' => '089876543210',
                'address' => 'Jl. Demo No. 2',
                'city' => 'Bandung',
                'province' => 'Jawa Barat',
                'country' => 'Indonesia',
                'status' => 'trial',
                'created_by' => $user->id,
            ]
        );

        $company1->users()->syncWithoutDetaching([
            $user->id => [
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => $now,
            ],
        ]);

        $company2->users()->syncWithoutDetaching([
            $user->id => [
                'role' => 'admin',
                'status' => 'active',
                'joined_at' => $now,
            ],
        ]);

        $tenantsDir = database_path('tenants');
        if (! File::isDirectory($tenantsDir)) {
            File::makeDirectory($tenantsDir, 0755, true);
        }

        $tenant1Name = 'company_000001.sqlite';
        $tenant1Path = database_path('tenants/'.$tenant1Name);
        $this->ensureSqliteFileExists($tenant1Path);

        $tenant2Name = 'company_000002.sqlite';
        $tenant2Path = database_path('tenants/'.$tenant2Name);
        $this->ensureSqliteFileExists($tenant2Path);

        TenantDatabase::updateOrCreate(
            ['company_id' => $company1->id],
            [
                'database_name' => $tenant1Name,
                'database_path' => $tenant1Path,
                'driver' => 'sqlite',
                'status' => 'active',
            ]
        );

        TenantDatabase::updateOrCreate(
            ['company_id' => $company2->id],
            [
                'database_name' => $tenant2Name,
                'database_path' => $tenant2Path,
                'driver' => 'sqlite',
                'status' => 'active',
            ]
        );

        // Tidak ada lagi langganan demo trial/free — Fase 3 membuang konsep
        // trial, dan langganan sekarang menempel di client (`SubscriptionService`),
        // bukan dibuat langsung di sini per perusahaan. Client tanpa baris
        // `subscriptions` sama sekali dianggap "belum dibackfill", bukan
        // terkunci — lihat SubscriptionService::stateFor().
    }

    private function ensureSqliteFileExists(string $path): void
    {
        if (File::exists($path)) {
            return;
        }

        File::put($path, '');
    }
}

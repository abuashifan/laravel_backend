<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Services\Companies\CompanyUserAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class SeedDemoCompaniesCommand extends Command
{
    protected $signature = 'company:seed-demo';

    protected $description = 'Seed demo companies and assignments (idempotent, internal only)';

    public function handle(CompanyUserAssignmentService $assignmentService): int
    {
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
                'status' => 'active',
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
                'status' => 'active',
                'created_by' => $user->id,
            ]
        );

        $tenantsDir = database_path('tenants');
        if (! File::isDirectory($tenantsDir)) {
            File::makeDirectory($tenantsDir, 0755, true);
        }

        $this->ensureTenantDatabase($company1->id, 'company_000001.sqlite');
        $this->ensureTenantDatabase($company2->id, 'company_000002.sqlite');

        // assignments (reactivate if inactive)
        $assignmentService->assign([
            'company_id' => $company1->id,
            'email' => $user->email,
            'role' => 'owner',
        ]);

        $assignmentService->assign([
            'company_id' => $company2->id,
            'email' => $user->email,
            'role' => 'admin',
        ]);

        $this->info('Demo seed completed successfully.');
        $this->line('User: '.$user->email);
        $this->line('Companies: '.$company1->name.', '.$company2->name);

        return self::SUCCESS;
    }

    private function ensureTenantDatabase(int $companyId, string $databaseName): void
    {
        $databasePath = database_path('tenants/'.$databaseName);
        if (! File::exists($databasePath)) {
            File::put($databasePath, '');
        }

        TenantDatabase::updateOrCreate(
            ['company_id' => $companyId],
            [
                'database_name' => $databaseName,
                'database_path' => $databasePath,
                'driver' => 'sqlite',
                'status' => 'active',
            ]
        );
    }
}

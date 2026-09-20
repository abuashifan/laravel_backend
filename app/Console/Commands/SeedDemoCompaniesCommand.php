<?php

namespace App\Console\Commands;

use App\Modules\Companies\Services\CompanyUserAssignmentService;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\Storage\TenantStorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class SeedDemoCompaniesCommand extends Command
{
    protected $signature = 'company:seed-demo';

    protected $description = 'Seed demo companies and assignments (idempotent, internal only)';

    public function __construct(private readonly TenantStorageManager $storages)
    {
        parent::__construct();
    }

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

    /**
     * `$databaseName` yang diteruskan pemanggil hanya berlaku untuk SQLite.
     * Nama kanonik selalu ditanyakan ke penyimpanan yang berlaku supaya demo
     * seeding tetap jalan saat tenant disimpan sebagai schema Postgres.
     */
    private function ensureTenantDatabase(int $companyId, string $databaseName): void
    {
        $storage = $this->storages->default();
        $name = $storage->driver() === 'sqlite' ? $databaseName : $storage->nameFor($companyId);
        $path = $storage->pathFor($name);

        if (! $storage->exists($name, $path)) {
            $storage->create($name, $path);
        }

        TenantDatabase::updateOrCreate(
            ['company_id' => $companyId],
            [
                'database_name' => $name,
                'database_path' => $path,
                'driver' => $storage->driver(),
                'status' => 'active',
            ]
        );
    }
}

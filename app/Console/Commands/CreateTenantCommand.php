<?php

namespace App\Console\Commands;

use App\Services\Tenant\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {--name= : Company name}
        {--slug= : Company slug}
        {--owner-email= : Owner user email}';

    protected $description = 'Create a new tenant (company + tenant sqlite) via internal command';

    public function handle(TenantProvisioningService $provisioningService): int
    {
        $name = (string) ($this->option('name') ?? '');
        $slug = (string) ($this->option('slug') ?? '');
        $ownerEmail = (string) ($this->option('owner-email') ?? '');

        if (trim($name) === '') {
            $name = (string) $this->ask('Company name');
        }

        if (trim($slug) === '') {
            $slug = (string) $this->ask('Company slug');
        }

        if (trim($ownerEmail) === '') {
            $ownerEmail = (string) $this->ask('Owner user email');
        }

        $validator = Validator::make(
            ['name' => $name, 'slug' => $slug, 'owner_email' => $ownerEmail],
            [
                'name' => ['required', 'string'],
                'slug' => ['required', 'string'],
                'owner_email' => ['required', 'email'],
            ]
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }

        try {
            $result = $provisioningService->provision($name, $slug, $ownerEmail);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $company = $result['company'];
        $databaseName = $result['database_name'];
        $databasePath = $result['database_path'];
        $relativePath = 'database/tenants/'.$databaseName;

        $this->info('Tenant created successfully.');
        $this->newLine();
        $this->line('Company ID: '.$company->id);
        $this->line('Company Name: '.$company->name);
        $this->line('Company Slug: '.$company->slug);
        $this->line('Owner Email: '.$ownerEmail);
        $this->line('Tenant Database: '.$databaseName);
        $this->line('Tenant Path: '.$relativePath);

        return self::SUCCESS;
    }
}


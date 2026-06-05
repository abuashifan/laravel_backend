<?php

namespace App\Console\Commands;

use App\Services\Companies\CompanyUserAssignmentService;
use Illuminate\Console\Command;

class AssignCompanyUserCommand extends Command
{
    protected $signature = 'company:assign-user
        {--company-id= : Company ID}
        {--email= : User email}
        {--role= : Role (owner|admin|staff|viewer)}';

    protected $description = 'Assign a user to a company (internal only)';

    public function handle(CompanyUserAssignmentService $service): int
    {
        $companyId = (string) ($this->option('company-id') ?? '');
        $email = (string) ($this->option('email') ?? '');
        $role = (string) ($this->option('role') ?? '');

        if (trim($companyId) === '') {
            $companyId = (string) $this->ask('Company ID');
        }

        if (trim($email) === '') {
            $email = (string) $this->ask('User email');
        }

        if (trim($role) === '') {
            $role = (string) $this->choice('Role', ['owner', 'admin', 'staff', 'viewer'], 0);
        }

        try {
            $assignment = $service->assign([
                'company_id' => (int) $companyId,
                'email' => $email,
                'role' => $role,
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('User assigned to company successfully.');
        $this->newLine();
        $this->line('Company ID: '.$assignment->company_id);
        $this->line('Company Name: '.($assignment->company?->name ?? '-'));
        $this->line('User ID: '.$assignment->user_id);
        $this->line('User Email: '.$email);
        $this->line('Role: '.$assignment->role);
        $this->line('Status: '.$assignment->status);

        return self::SUCCESS;
    }
}

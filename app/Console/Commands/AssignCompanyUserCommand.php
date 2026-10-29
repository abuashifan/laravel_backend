<?php

namespace App\Console\Commands;

use App\Modules\Companies\Services\CompanyUserAssignmentService;
use Illuminate\Console\Command;

class AssignCompanyUserCommand extends Command
{
    protected $signature = 'company:assign-user
        {--company-id= : Company ID}
        {--email= : User email}
        {--full-access : Berikan akses penuh}
        {--permissions= : Kunci izin matriks dipisahkan koma}';

    protected $description = 'Assign a user to a company (internal only)';

    public function handle(CompanyUserAssignmentService $service): int
    {
        $companyId = (string) ($this->option('company-id') ?? '');
        $email = (string) ($this->option('email') ?? '');
        $permissions = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('permissions')))));
        $fullAccess = (bool) $this->option('full-access');

        if (trim($companyId) === '') {
            $companyId = (string) $this->ask('Company ID');
        }

        if (trim($email) === '') {
            $email = (string) $this->ask('User email');
        }

        try {
            $assignment = $service->assign([
                'company_id' => (int) $companyId,
                'email' => $email,
                'full_access' => $fullAccess,
                'permissions' => $permissions,
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
        $this->line('Full access: '.($assignment->full_access ? 'yes' : 'no'));
        $this->line('Permissions: '.implode(', ', $permissions));
        $this->line('Status: '.$assignment->status);

        return self::SUCCESS;
    }
}

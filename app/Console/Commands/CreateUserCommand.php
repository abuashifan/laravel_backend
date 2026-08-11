<?php

namespace App\Console\Commands;

use App\Modules\Companies\Services\CompanyUserAssignmentService;
use App\Shared\Users\UserRegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create
        {--name= : Nama user}
        {--email= : Email user}
        {--password= : Password user (min 8 karakter)}
        {--assign-company= : Opsional, company_id yang langsung diberikan ke user}
        {--role=owner : Role di company saat --assign-company dipakai}';

    protected $description = 'Buat akun user baru (registrasi mandiri ditutup, hanya owner aplikasi yang membuat akun)';

    public function handle(
        UserRegistrationService $registrationService,
        CompanyUserAssignmentService $assignmentService
    ): int {
        $name = (string) ($this->option('name') ?? '');
        $email = (string) ($this->option('email') ?? '');
        $password = (string) ($this->option('password') ?? '');

        if (trim($name) === '') {
            $name = (string) $this->ask('Nama user');
        }

        if (trim($email) === '') {
            $email = (string) $this->ask('Email user');
        }

        if (trim($password) === '') {
            $password = (string) $this->secret('Password user (min 8 karakter)');
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            UserRegistrationService::rules()
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        try {
            $user = $registrationService->register([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('User created successfully.');
        $this->newLine();
        $this->line('User ID: '.$user->id);
        $this->line('Name: '.$user->name);
        $this->line('Email: '.$user->email);

        $companyIdOption = $this->option('assign-company');

        if ($companyIdOption === null || $companyIdOption === '') {
            $this->newLine();
            $this->line('User belum punya perusahaan. Setelah login, ia bisa membuat perusahaannya sendiri dari halaman pilih perusahaan.');

            return self::SUCCESS;
        }

        try {
            $assignment = $assignmentService->assign([
                'company_id' => (int) $companyIdOption,
                'email' => $user->email,
                'role' => (string) $this->option('role'),
            ]);
        } catch (\Throwable $e) {
            // User sudah terbuat; assignment yang gagal tidak membatalkannya.
            $this->newLine();
            $this->error('User dibuat, tapi assignment ke company gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Assigned to company ID: '.$assignment->company_id.' sebagai '.$assignment->role);

        return self::SUCCESS;
    }
}

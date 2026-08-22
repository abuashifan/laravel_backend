<?php

namespace App\Shared\Tenant;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;

class TenantContext
{
    protected ?Company $company = null;

    protected ?CompanyUser $companyUser = null;

    protected ?TenantDatabase $tenantDatabase = null;

    public function set(Company $company, CompanyUser $companyUser, TenantDatabase $tenantDatabase): void
    {
        $this->company = $company;
        $this->companyUser = $companyUser;
        $this->tenantDatabase = $tenantDatabase;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function companyUser(): ?CompanyUser
    {
        return $this->companyUser;
    }

    public function tenantDatabase(): ?TenantDatabase
    {
        return $this->tenantDatabase;
    }

    public function companyId(): ?int
    {
        return $this->company?->id;
    }

    public function role(): ?string
    {
        return $this->companyUser?->role;
    }

    public function databaseName(): ?string
    {
        return $this->tenantDatabase?->database_name;
    }

    public function databasePath(): ?string
    {
        return $this->tenantDatabase?->database_path;
    }
}

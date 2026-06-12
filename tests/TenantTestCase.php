<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Company;
use App\Models\User;
use Tests\Feature\Journal\JournalTestCase;

abstract class TenantTestCase extends JournalTestCase
{
    protected User $user;

    protected Company $company;

    /**
     * @var array<string, string>
     */
    protected array $headers = [];

    protected string $tenantPath;

    protected function setUp(): void
    {
        parent::setUp();

        $context = $this->setUpTenant(role: $this->tenantRole(), accountingSettingOverrides: $this->accountingSettingOverrides());

        $this->user = $context['user'];
        $this->company = $context['company'];
        $this->headers = $context['headers'];
        $this->tenantPath = $context['tenant_path'];
    }

    protected function tenantRole(): string
    {
        return 'warehouse';
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountingSettingOverrides(): array
    {
        return [
            'transaction_workflow_mode' => 'draft_approve_post',
            'auto_post_transactions' => false,
            'approval_enabled' => true,
        ];
    }
}

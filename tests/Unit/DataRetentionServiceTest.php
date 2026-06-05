<?php

namespace Tests\Unit;

use App\Services\DataRetention\DataRetentionService;
use App\Services\DataRetention\DataRetentionValidator;
use App\Support\DataRetention\DataRetentionPolicy;
use App\Support\DataRetention\RetentionDecision;
use Carbon\Carbon;
use Tests\TestCase;

class DataRetentionServiceTest extends TestCase
{
    public function test_default_policy_disables_auto_archive_voids_and_purge(): void
    {
        $policy = DataRetentionPolicy::defaults();
        $this->assertFalse($policy->autoArchiveVoidsEnabled());
        $this->assertFalse($policy->allowsPurge());
    }

    public function test_voided_transaction_default_decision_is_hide(): void
    {
        $service = $this->app->make(DataRetentionService::class);
        $decision = $service->decideForVoidedTransaction(['voided_at' => now()->toDateString()]);

        $this->assertTrue($decision->allowed());
        $this->assertSame('hide', $decision->action);
    }

    public function test_voided_transaction_not_archive_eligible_when_auto_archive_disabled(): void
    {
        $service = $this->app->make(DataRetentionService::class);
        $policy = $service->policy(['auto_archive_voided_transactions' => false, 'archive_voided_after_days' => 1]);

        $decision = $service->decideForVoidedTransaction(['voided_at' => now()->subDays(10)->toDateString()], $policy);
        $this->assertSame('hide', $decision->action);
    }

    public function test_voided_transaction_archive_eligible_when_auto_archive_enabled(): void
    {
        $service = $this->app->make(DataRetentionService::class);
        $policy = $service->policy(['auto_archive_voided_transactions' => true, 'archive_voided_after_days' => 1]);

        $decision = $service->decideForVoidedTransaction(['voided_at' => now()->subDays(10)->toDateString()], $policy);
        $this->assertSame('archive_eligible', $decision->action);
    }

    public function test_closed_fiscal_year_default_decision_is_keep(): void
    {
        $service = $this->app->make(DataRetentionService::class);
        $decision = $service->decideForClosedFiscalYear(['closed_at' => now()->subYears(10)->toDateString()]);

        $this->assertSame('keep', $decision->action);
    }

    public function test_closed_fiscal_year_archive_eligible_when_enabled_and_old_enough(): void
    {
        $service = $this->app->make(DataRetentionService::class);
        $policy = $service->policy(['auto_archive_closed_fiscal_years' => true, 'archive_closed_fiscal_year_after_years' => 5]);

        $decision = $service->decideForClosedFiscalYear(['closed_at' => now()->subYears(10)->toDateString()], $policy);
        $this->assertSame('archive_eligible', $decision->action);
    }

    public function test_purge_blocked_when_allow_purge_archived_data_false(): void
    {
        $service = $this->app->make(DataRetentionService::class);
        $policy = $service->policy(['allow_purge_archived_data' => false]);

        $decision = $service->canPurge(['is_archived' => true], $policy);
        $this->assertTrue($decision->blocked());
    }

    public function test_purge_blocked_when_record_not_archived(): void
    {
        $service = $this->app->make(DataRetentionService::class);
        $policy = $service->policy(['allow_purge_archived_data' => true]);

        $decision = $service->canPurge(['is_archived' => false], $policy);
        $this->assertTrue($decision->blocked());
    }

    public function test_validator_rejects_negative_retention_days_and_accepts_null(): void
    {
        $validator = $this->app->make(DataRetentionValidator::class);
        $this->assertFalse($validator->validatePolicy(['archive_voided_after_days' => -1])['valid']);
        $this->assertTrue($validator->validatePolicy(['archive_voided_after_days' => null])['valid']);
    }

    public function test_retention_decision_to_array_structure(): void
    {
        $decision = RetentionDecision::hide();
        $arr = $decision->toArray();
        $this->assertSame(['action', 'allowed', 'code', 'message', 'reasons', 'meta'], array_keys($arr));
    }
}


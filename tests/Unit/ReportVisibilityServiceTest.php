<?php

namespace Tests\Unit;

use App\Services\Reports\ReportVisibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportVisibilityServiceTest extends TestCase
{
    public function test_draft_transaction_visible_by_default(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isTransactionVisible('draft'));
    }

    public function test_approved_transaction_visible_by_default(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isTransactionVisible('approved'));
    }

    public function test_posted_transaction_visible_by_default(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isTransactionVisible('posted'));
    }

    public function test_void_transaction_hidden_by_default(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isTransactionVisible('void'));
    }

    public function test_void_transaction_visible_when_include_void_true(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isTransactionVisible('void', true));
    }

    public function test_posted_transaction_reportable(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isTransactionReportable('posted'));
    }

    public function test_draft_transaction_not_reportable(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isTransactionReportable('draft'));
    }

    public function test_approved_transaction_not_reportable(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isTransactionReportable('approved'));
    }

    public function test_void_transaction_not_reportable(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isTransactionReportable('void'));
    }

    public function test_posted_journal_reportable_when_not_obsolete(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isJournalReportable('posted', false));
    }

    public function test_posted_journal_not_reportable_when_obsolete(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isJournalReportable('posted', true));
    }

    public function test_void_journal_not_reportable(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isJournalReportable('void', false));
    }

    public function test_draft_journal_not_reportable(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isJournalReportable('draft', false));
    }

    public function test_obsolete_effect_not_reportable(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isEffectReportable('posted', true));
    }

    public function test_audit_view_can_include_void_and_obsolete(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isVisibleInAudit('void', true));
    }

    public function test_revision_view_can_include_obsolete(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isVisibleInRevision('posted', true));
    }

    public function test_should_hide_voided_transactions_returns_company_setting_value_when_provided(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $setting = (object) ['hide_voided_transactions' => false];

        $this->assertFalse($service->shouldHideVoidedTransactions($setting));
    }

    public function test_should_hide_voided_transactions_returns_default_true_when_setting_missing(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->shouldHideVoidedTransactions(null));
    }

    public function test_closed_fiscal_year_visible_returns_true(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isClosedFiscalYearVisible());
    }

    public function test_closed_fiscal_year_read_only_returns_true(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertTrue($service->isClosedFiscalYearReadOnly());
    }

    public function test_unknown_status_is_not_visible_or_reportable(): void
    {
        $service = $this->app->make(ReportVisibilityService::class);
        $this->assertFalse($service->isTransactionVisible('unknown'));
        $this->assertFalse($service->isTransactionReportable('unknown'));
    }
}


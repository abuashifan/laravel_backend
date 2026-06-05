<?php

namespace Tests\Feature\DocumentNumbering;

use App\Models\Company;
use App\Models\DocumentNumberSequence;
use App\Models\DocumentNumberingSetting;
use App\Models\User;
use App\Services\Accounting\FiscalYearService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        $user = User::factory()->create(['status' => 'active']);

        return Company::query()->create([
            'name' => 'Company DN Test',
            'slug' => 'company-dn-test-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    public function test_ensure_default_settings_creates_all_default_document_types(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $service->ensureDefaultSettings($company);

        $this->assertSame(count(DocumentType::all()), DocumentNumberingSetting::query()->where('company_id', $company->id)->count());
    }

    public function test_get_or_create_setting_creates_sales_invoice_setting_with_prefix_si(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $setting = $service->getOrCreateSetting($company, DocumentType::SALES_INVOICE);
        $this->assertSame('SI', $setting->prefix);
    }

    public function test_generate_sales_invoice_number_increments_sequence(): void
    {
        $company = $this->makeCompany();
        $fyService = $this->app->make(FiscalYearService::class);
        $fyService->createFiscalYear($company, 2026, '2026-01-01', '2026-12-31');

        $service = $this->app->make(DocumentNumberService::class);

        $n1 = $service->generate($company, DocumentType::SALES_INVOICE, '2026-05-17');
        $n2 = $service->generate($company, DocumentType::SALES_INVOICE, '2026-05-17');

        $this->assertSame('SI-2026-000001', $n1);
        $this->assertSame('SI-2026-000002', $n2);

        $seq = DocumentNumberSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', DocumentType::SALES_INVOICE)
            ->where('period_key', '2026')
            ->first();

        $this->assertNotNull($seq);
        $this->assertSame(2, (int) $seq->last_number);
    }

    public function test_purchase_invoice_uses_different_prefix(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $n = $service->generate($company, DocumentType::PURCHASE_INVOICE, '2026-05-17');
        $this->assertSame('PI-2026-000001', $n);
    }

    public function test_journal_entry_uses_prefix_jv(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $n = $service->generate($company, DocumentType::JOURNAL_ENTRY, '2026-05-17');
        $this->assertSame('JV-2026-000001', $n);
    }

    public function test_numbering_resets_per_fiscal_year(): void
    {
        $company = $this->makeCompany();
        $fyService = $this->app->make(FiscalYearService::class);
        $fyService->createFiscalYear($company, 2026, '2026-01-01', '2026-12-31');
        $fyService->closeFiscalYear($company->activeFiscalYear()->firstOrFail(), $company->created_by);
        $fyService->createFiscalYear($company, 2027, '2027-01-01', '2027-12-31');

        $service = $this->app->make(DocumentNumberService::class);

        $n1 = $service->generate($company, DocumentType::SALES_INVOICE, '2026-05-17');
        $n2 = $service->generate($company, DocumentType::SALES_INVOICE, '2027-01-01');

        $this->assertSame('SI-2026-000001', $n1);
        $this->assertSame('SI-2027-000001', $n2);
    }

    public function test_preview_does_not_increment_sequence_and_generate_after_preview_returns_same_number(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $preview = $service->preview($company, DocumentType::SALES_INVOICE, '2026-05-17');
        $this->assertSame('SI-2026-000001', $preview);

        $this->assertSame(0, DocumentNumberSequence::query()->count());

        $generated = $service->generate($company, DocumentType::SALES_INVOICE, '2026-05-17');
        $this->assertSame('SI-2026-000001', $generated);
    }

    public function test_reset_period_monthly_uses_period_key_year_month(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $setting = $service->getOrCreateSetting($company, DocumentType::SALES_INVOICE);
        $setting->reset_period = 'monthly';
        $setting->save();

        $service->generate($company, DocumentType::SALES_INVOICE, '2026-05-17');

        $this->assertNotNull(
            DocumentNumberSequence::query()
                ->where('company_id', $company->id)
                ->where('document_type', DocumentType::SALES_INVOICE)
                ->where('period_key', '2026-05')
                ->first()
        );
    }

    public function test_reset_period_never_uses_period_key_all(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $setting = $service->getOrCreateSetting($company, DocumentType::SALES_INVOICE);
        $setting->reset_period = 'never';
        $setting->save();

        $service->generate($company, DocumentType::SALES_INVOICE, '2026-05-17');

        $this->assertNotNull(
            DocumentNumberSequence::query()
                ->where('company_id', $company->id)
                ->where('document_type', DocumentType::SALES_INVOICE)
                ->where('period_key', 'all')
                ->first()
        );
    }

    public function test_padding_works(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $setting = $service->getOrCreateSetting($company, DocumentType::SALES_INVOICE);
        $setting->padding = 4;
        $setting->save();

        $n = $service->generate($company, DocumentType::SALES_INVOICE, '2026-05-17');
        $this->assertSame('SI-2026-0001', $n);
    }

    public function test_manual_number_validation_respects_allow_manual_number_flag(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $this->assertFalse($service->validateManualNumber($company, DocumentType::SALES_INVOICE, 'SI-2026-ABC'));

        $setting = $service->getOrCreateSetting($company, DocumentType::SALES_INVOICE);
        $setting->allow_manual_number = true;
        $setting->save();

        $this->assertTrue($service->validateManualNumber($company, DocumentType::SALES_INVOICE, 'SI-2026-ABC'));
    }

    public function test_unknown_document_type_throws_exception(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->getOrCreateSetting($company, 'unknown_type');
    }

    public function test_inactive_setting_blocks_generate(): void
    {
        $company = $this->makeCompany();
        $service = $this->app->make(DocumentNumberService::class);

        $setting = $service->getOrCreateSetting($company, DocumentType::SALES_INVOICE);
        $setting->is_active = false;
        $setting->save();

        $this->expectException(\RuntimeException::class);
        $service->generate($company, DocumentType::SALES_INVOICE, '2026-05-17');
    }
}


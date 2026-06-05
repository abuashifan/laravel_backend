<?php

namespace App\Services\DocumentNumbering;

use App\Models\Company;
use App\Models\DocumentNumberingSetting;
use App\Models\DocumentNumberSequence;
use App\Models\FiscalYear;
use App\Services\Accounting\FiscalYearService;
use App\Support\DocumentNumbering\DocumentNumberFormat;
use App\Support\DocumentNumbering\DocumentType;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DocumentNumberService
{
    public function __construct(private readonly ?FiscalYearService $fiscalYearService = null)
    {
    }

    public function ensureDefaultSettings(Company $company): void
    {
        foreach ((array) config('document_numbers.document_types', []) as $documentType => $def) {
            $this->getOrCreateSetting($company, (string) $documentType);
        }
    }

    public function getOrCreateSetting(Company $company, string $documentType): DocumentNumberingSetting
    {
        if (! DocumentType::exists($documentType)) {
            throw new InvalidArgumentException('UNKNOWN_DOCUMENT_TYPE');
        }

        $existing = DocumentNumberingSetting::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->first();

        if ($existing) {
            return $existing;
        }

        $def = (array) config('document_numbers.document_types.'.$documentType, []);
        $prefix = (string) ($def['prefix'] ?? DocumentType::defaultPrefix($documentType) ?? strtoupper(substr($documentType, 0, 2)));
        $name = $def['name'] ?? null;

        return DocumentNumberingSetting::query()->create([
            'company_id' => $company->id,
            'document_type' => $documentType,
            'name' => $name,
            'prefix' => $prefix,
            'format' => (string) config('document_numbers.default_format', '{PREFIX}-{YEAR}-{NUMBER}'),
            'reset_period' => (string) config('document_numbers.default_reset_period', 'fiscal_year'),
            'padding' => (int) config('document_numbers.default_padding', 6),
            'mode' => (string) config('document_numbers.default_mode', 'auto'),
            'allow_manual_number' => (bool) config('document_numbers.allow_manual_number_default', false),
            'allow_duplicate_number' => (bool) config('document_numbers.allow_duplicate_number_default', false),
            'is_active' => true,
        ]);
    }

    public function preview(Company $company, string $documentType, string $documentDate): string
    {
        $setting = $this->getOrCreateSetting($company, $documentType);
        if (! $setting->is_active) {
            throw new RuntimeException('NUMBERING_SETTING_INACTIVE');
        }

        $sequence = $this->peekNextSequence($company, $documentType, $documentDate);

        return $this->formatNumber($setting, $documentType, $documentDate, $sequence);
    }

    public function generate(Company $company, string $documentType, string $documentDate): string
    {
        $setting = $this->getOrCreateSetting($company, $documentType);

        if (! $setting->is_active) {
            throw new RuntimeException('NUMBERING_SETTING_INACTIVE');
        }

        $sequence = $this->nextSequence($company, $documentType, $documentDate, increment: true);

        return $this->formatNumber($setting, $documentType, $documentDate, $sequence);
    }

    public function validateManualNumber(Company $company, string $documentType, string $documentNumber): bool
    {
        $setting = $this->getOrCreateSetting($company, $documentType);

        if (! $setting->allowsManualNumber()) {
            return false;
        }

        // Phase 4G: belum ada tabel transaksi nyata untuk cek duplicate document number.
        // Saat modul transaksi dibuat, module wajib melakukan duplicate check ke tabel transaksi-nya sendiri.
        if (! $setting->allowsDuplicateNumber()) {
            return $this->assertManualNumberAvailable($company, $documentType, $documentNumber);
        }

        return true;
    }

    public function nextSequence(
        Company $company,
        string $documentType,
        string $documentDate,
        bool $increment = true
    ): int {
        $setting = $this->getOrCreateSetting($company, $documentType);
        $fiscalYear = $this->fiscalYearForDate($company, $documentDate);
        $periodKey = $this->periodKeyFor($setting, $documentDate, $fiscalYear);

        return DB::transaction(function () use ($company, $documentType, $fiscalYear, $periodKey, $increment) {
            $sequence = $this->getOrCreateSequenceLocked($company, $documentType, $fiscalYear, $periodKey);

            if (! $increment) {
                return (int) $sequence->last_number + 1;
            }

            $sequence->last_number = (int) $sequence->last_number + 1;
            $sequence->save();

            return (int) $sequence->last_number;
        });
    }

    private function peekNextSequence(Company $company, string $documentType, string $documentDate): int
    {
        $setting = $this->getOrCreateSetting($company, $documentType);
        $fiscalYear = $this->fiscalYearForDate($company, $documentDate);
        $periodKey = $this->periodKeyFor($setting, $documentDate, $fiscalYear);

        $sequence = DocumentNumberSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('period_key', $periodKey)
            ->first();

        return $sequence ? ((int) $sequence->last_number + 1) : 1;
    }

    public function periodKeyFor(DocumentNumberingSetting $setting, string $documentDate, ?FiscalYear $fiscalYear = null): string
    {
        $reset = $setting->reset_period ?: 'fiscal_year';
        $date = Carbon::parse($documentDate);

        if ($reset === 'never') {
            return 'all';
        }

        if ($reset === 'monthly') {
            return $date->format('Y-m');
        }

        // default fiscal_year
        return (string) ($fiscalYear?->year ?? $date->format('Y'));
    }

    public function fiscalYearForDate(Company $company, string $documentDate): ?FiscalYear
    {
        if (! $this->fiscalYearService) {
            return null;
        }

        return $this->fiscalYearService->fiscalYearForDate($company, $documentDate);
    }

    private function formatNumber(
        DocumentNumberingSetting $setting,
        string $documentType,
        string $documentDate,
        int $sequence
    ): string {
        $date = Carbon::parse($documentDate);

        return DocumentNumberFormat::format($setting->format, [
            'prefix' => $setting->prefix,
            'year' => $date->format('Y'),
            'month' => $date->format('m'),
            'number' => DocumentNumberFormat::padNumber($sequence, (int) $setting->padding),
            'document_type' => $documentType,
        ]);
    }

    private function getOrCreateSequenceLocked(
        Company $company,
        string $documentType,
        ?FiscalYear $fiscalYear,
        string $periodKey
    ): DocumentNumberSequence {
        $baseQuery = DocumentNumberSequence::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->where('period_key', $periodKey);

        $sequence = (clone $baseQuery)->lockForUpdate()->first();
        if ($sequence) {
            return $sequence;
        }

        try {
            DocumentNumberSequence::query()->create([
                'company_id' => $company->id,
                'document_type' => $documentType,
                'fiscal_year_id' => $fiscalYear?->id,
                'period_key' => $periodKey,
                'last_number' => 0,
            ]);
        } catch (QueryException $e) {
            // race condition: row already created
        }

        $sequence = (clone $baseQuery)->lockForUpdate()->first();
        if (! $sequence) {
            throw new RuntimeException('FAILED_TO_RESOLVE_DOCUMENT_SEQUENCE');
        }

        if ($sequence->fiscal_year_id === null && $fiscalYear?->id) {
            $sequence->fiscal_year_id = $fiscalYear->id;
            $sequence->save();
        }

        return $sequence;
    }

    private function assertManualNumberAvailable(Company $company, string $documentType, string $documentNumber): bool
    {
        // Phase 4G placeholder: tidak bisa cek duplicate ke tabel transaksi karena modul belum ada.
        // Saat modul transaksi dibuat, implementasi duplicate check harus dilakukan per-module (tenant database).
        return true;
    }
}

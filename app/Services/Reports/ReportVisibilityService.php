<?php

namespace App\Services\Reports;

use App\Models\CompanyAccountingSetting;

class ReportVisibilityService
{
    public function isTransactionVisible(?string $status, bool $includeVoid = false): bool
    {
        if (! $status) {
            return false;
        }

        $status = trim($status);

        $visible = (array) config('report_visibility.transaction_visible_statuses', []);
        $auditVisible = (array) config('report_visibility.audit_visible_statuses', []);

        if ($includeVoid) {
            return in_array($status, $auditVisible, true);
        }

        return in_array($status, $visible, true);
    }

    public function isTransactionReportable(?string $status): bool
    {
        if (! $status) {
            return false;
        }

        $status = trim($status);

        return in_array($status, (array) config('report_visibility.reportable_transaction_statuses', []), true);
    }

    public function isJournalReportable(?string $status, bool $isObsolete = false): bool
    {
        if (! $status) {
            return false;
        }

        if ($isObsolete && (bool) config('report_visibility.exclude_obsolete_from_reports', true)) {
            return false;
        }

        $status = trim($status);

        return in_array($status, (array) config('report_visibility.reportable_journal_statuses', []), true);
    }

    public function isEffectReportable(?string $status, bool $isObsolete = false): bool
    {
        return $this->isJournalReportable($status, $isObsolete);
    }

    public function isVisibleInAudit(?string $status, bool $isObsolete = false): bool
    {
        if (! $status) {
            return false;
        }

        $status = trim($status);

        // audit includes void and can include obsolete
        return in_array($status, (array) config('report_visibility.audit_visible_statuses', []), true);
    }

    public function isVisibleInRevision(?string $status, bool $isObsolete = false): bool
    {
        // revision view can include obsolete effects, but still respects known statuses
        return $this->isVisibleInAudit($status, $isObsolete);
    }

    public function shouldHideVoidedTransactions(?object $companySetting = null): bool
    {
        if ($companySetting && isset($companySetting->hide_voided_transactions)) {
            return (bool) $companySetting->hide_voided_transactions;
        }

        return (bool) config('report_visibility.default_hide_voided_transactions', true);
    }

    public function isClosedFiscalYearVisible(): bool
    {
        return (bool) config('report_visibility.closed_fiscal_year_visible', true);
    }

    public function isClosedFiscalYearReadOnly(): bool
    {
        return (bool) config('report_visibility.closed_fiscal_year_read_only', true);
    }
}


<?php

namespace App\Services\DataRetention;

use App\Support\DataRetention\DataRetentionPolicy;
use App\Support\DataRetention\RetentionDecision;
use Carbon\Carbon;

class DataRetentionService
{
    public function policy(array $override = []): DataRetentionPolicy
    {
        $base = (array) config('data_retention.default_policy', []);
        return DataRetentionPolicy::fromArray(array_merge($base, $override));
    }

    public function decideForVoidedTransaction(array|object $record, ?DataRetentionPolicy $policy = null): RetentionDecision
    {
        $policy ??= DataRetentionPolicy::defaults();

        $voidedAt = $this->getDate($record, 'voided_at') ?? $this->getDate($record, 'updated_at') ?? null;

        if (! $policy->autoArchiveVoidsEnabled()) {
            return RetentionDecision::hide('Voided transactions are kept for audit and hidden from normal UI.');
        }

        if ($policy->archiveVoidedAfterDays === null || $voidedAt === null) {
            return RetentionDecision::hide('Voided transactions are kept for audit and hidden from normal UI.');
        }

        $ageDays = Carbon::parse($voidedAt)->diffInDays(now());

        if ($ageDays >= $policy->archiveVoidedAfterDays) {
            return RetentionDecision::archiveEligible('Voided transaction is eligible for archive by policy.', [], [
                'age_days' => $ageDays,
            ]);
        }

        return RetentionDecision::hide('Voided transactions are kept for audit and hidden from normal UI.', [
            'age_days' => $ageDays,
        ]);
    }

    public function decideForClosedFiscalYear(array|object $fiscalYear, ?DataRetentionPolicy $policy = null): RetentionDecision
    {
        $policy ??= DataRetentionPolicy::defaults();

        // Closed fiscal year data stays visible read-only by default.
        if (! $policy->autoArchiveClosedFiscalYearsEnabled()) {
            return RetentionDecision::keep('Closed fiscal year data remains visible for historical reports and is read-only.');
        }

        $closedAt = $this->getDate($fiscalYear, 'closed_at') ?? $this->getDate($fiscalYear, 'end_date');
        if (! $closedAt || $policy->archiveClosedFiscalYearAfterYears === null) {
            return RetentionDecision::keep('Closed fiscal year data remains visible for historical reports and is read-only.');
        }

        $ageYears = Carbon::parse($closedAt)->diffInYears(now());

        if ($ageYears >= $policy->archiveClosedFiscalYearAfterYears) {
            return RetentionDecision::archiveEligible('Closed fiscal year is eligible for archive by policy.', [], [
                'age_years' => $ageYears,
            ]);
        }

        return RetentionDecision::keep('Closed fiscal year data remains visible for historical reports and is read-only.', [
            'age_years' => $ageYears,
        ]);
    }

    public function canPurge(array|object $record, ?DataRetentionPolicy $policy = null): RetentionDecision
    {
        $policy ??= DataRetentionPolicy::defaults();

        if (! $policy->allowsPurge()) {
            return RetentionDecision::block('PURGE_NOT_ALLOWED', 'Purge is not allowed by policy.');
        }

        $archived = $this->getBool($record, 'is_archived') ?? false;
        if (! $archived) {
            return RetentionDecision::block('PURGE_REQUIRES_ARCHIVED', 'Purge is only allowed for archived data.');
        }

        if ($this->requiresBackupBeforePurge()) {
            return RetentionDecision::block('PURGE_REQUIRES_BACKUP', 'Purge requires backup confirmation.');
        }

        if ($this->requiresAuditLogBeforePurge()) {
            return RetentionDecision::block('PURGE_REQUIRES_AUDIT_LOG', 'Purge requires audit log.');
        }

        if ($this->requiresPreviewBeforePurge()) {
            return RetentionDecision::block('PURGE_REQUIRES_PREVIEW', 'Purge requires preview.');
        }

        return RetentionDecision::purgeEligible('Record is eligible for purge by policy.');
    }

    public function requiresBackupBeforePurge(): bool
    {
        return (bool) config('data_retention.rules.purge_requires_backup', true);
    }

    public function requiresAuditLogBeforePurge(): bool
    {
        return (bool) config('data_retention.rules.purge_requires_audit_log', true);
    }

    public function requiresPreviewBeforePurge(): bool
    {
        return (bool) config('data_retention.rules.purge_requires_preview', true);
    }

    private function getDate(array|object $record, string $key): ?string
    {
        if (is_array($record)) {
            return isset($record[$key]) ? (string) $record[$key] : null;
        }

        if (is_object($record)) {
            return isset($record->{$key}) ? (string) $record->{$key} : null;
        }

        return null;
    }

    private function getBool(array|object $record, string $key): ?bool
    {
        if (is_array($record)) {
            return array_key_exists($key, $record) ? (bool) $record[$key] : null;
        }

        if (is_object($record)) {
            return isset($record->{$key}) ? (bool) $record->{$key} : null;
        }

        return null;
    }
}


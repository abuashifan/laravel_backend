<?php

namespace App\Support\DataRetention;

class DataRetentionPolicy
{
    public function __construct(
        public readonly ?int $voidTransactionRetentionDays,
        public readonly bool $autoArchiveVoidedTransactions,
        public readonly ?int $archiveVoidedAfterDays,
        public readonly int $activeDataRetentionYears,
        public readonly bool $autoArchiveClosedFiscalYears,
        public readonly ?int $archiveClosedFiscalYearAfterYears,
        public readonly bool $allowPurgeArchivedData,
        public readonly ?int $purgeArchivedAfterYears,
        public readonly ?int $auditLogRetentionYears,
        public readonly ?int $revisionHistoryRetentionYears,
    ) {
    }

    public static function defaults(): self
    {
        return self::fromArray((array) config('data_retention.default_policy', []));
    }

    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['void_transaction_retention_days']) ? (is_null($data['void_transaction_retention_days']) ? null : (int) $data['void_transaction_retention_days']) : null,
            (bool) ($data['auto_archive_voided_transactions'] ?? false),
            isset($data['archive_voided_after_days']) ? (is_null($data['archive_voided_after_days']) ? null : (int) $data['archive_voided_after_days']) : null,
            (int) ($data['active_data_retention_years'] ?? 5),
            (bool) ($data['auto_archive_closed_fiscal_years'] ?? false),
            isset($data['archive_closed_fiscal_year_after_years']) ? (is_null($data['archive_closed_fiscal_year_after_years']) ? null : (int) $data['archive_closed_fiscal_year_after_years']) : null,
            (bool) ($data['allow_purge_archived_data'] ?? false),
            isset($data['purge_archived_after_years']) ? (is_null($data['purge_archived_after_years']) ? null : (int) $data['purge_archived_after_years']) : null,
            isset($data['audit_log_retention_years']) ? (is_null($data['audit_log_retention_years']) ? null : (int) $data['audit_log_retention_years']) : null,
            isset($data['revision_history_retention_years']) ? (is_null($data['revision_history_retention_years']) ? null : (int) $data['revision_history_retention_years']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'void_transaction_retention_days' => $this->voidTransactionRetentionDays,
            'auto_archive_voided_transactions' => $this->autoArchiveVoidedTransactions,
            'archive_voided_after_days' => $this->archiveVoidedAfterDays,
            'active_data_retention_years' => $this->activeDataRetentionYears,
            'auto_archive_closed_fiscal_years' => $this->autoArchiveClosedFiscalYears,
            'archive_closed_fiscal_year_after_years' => $this->archiveClosedFiscalYearAfterYears,
            'allow_purge_archived_data' => $this->allowPurgeArchivedData,
            'purge_archived_after_years' => $this->purgeArchivedAfterYears,
            'audit_log_retention_years' => $this->auditLogRetentionYears,
            'revision_history_retention_years' => $this->revisionHistoryRetentionYears,
        ];
    }

    public function allowsPurge(): bool
    {
        return $this->allowPurgeArchivedData;
    }

    public function autoArchiveVoidsEnabled(): bool
    {
        return $this->autoArchiveVoidedTransactions;
    }

    public function autoArchiveClosedFiscalYearsEnabled(): bool
    {
        return $this->autoArchiveClosedFiscalYears;
    }
}


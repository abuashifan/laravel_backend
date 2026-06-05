<?php

namespace App\Services\DataRetention;

class DataRetentionValidator
{
    public function validatePolicy(array $data): array
    {
        $errors = [];

        foreach ([
            'void_transaction_retention_days',
            'archive_voided_after_days',
            'active_data_retention_years',
            'archive_closed_fiscal_year_after_years',
            'purge_archived_after_years',
            'audit_log_retention_years',
            'revision_history_retention_years',
        ] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            if (! is_int($data[$key]) && ! ctype_digit((string) $data[$key])) {
                $errors[] = $key.':INVALID_INTEGER';
                continue;
            }

            if ((int) $data[$key] < 0) {
                $errors[] = $key.':NEGATIVE_NOT_ALLOWED';
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => [],
        ];
    }

    public function validatePurgeRequest(array $data): array
    {
        $errors = [];

        if (empty($data['confirm_backup'])) {
            $errors[] = 'CONFIRM_BACKUP_REQUIRED';
        }

        if (empty($data['confirm_preview'])) {
            $errors[] = 'CONFIRM_PREVIEW_REQUIRED';
        }

        if (empty($data['reason'])) {
            $errors[] = 'REASON_REQUIRED';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => [],
        ];
    }

    public function validateArchiveRequest(array $data): array
    {
        $errors = [];

        if (empty($data['reason'])) {
            $errors[] = 'REASON_REQUIRED';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => [],
        ];
    }
}


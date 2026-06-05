<?php

namespace App\Services\OpeningBalance;

use App\Support\OpeningBalance\OpeningBalanceBatch;
use App\Support\OpeningBalance\OpeningBalanceLine;
use Carbon\Carbon;

class OpeningBalanceValidator
{
    public function validateBatch(OpeningBalanceBatch $batch): array
    {
        $errors = [];
        $warnings = [];

        $nonZeroLines = array_values(array_filter($batch->lines(), fn (OpeningBalanceLine $l) => ! $l->isZero()));

        if (count($nonZeroLines) < 2) {
            $errors[] = 'BATCH_MINIMUM_LINES';
        }

        foreach ($batch->lines() as $idx => $line) {
            foreach ($this->validateLine($line) as $err) {
                $errors[] = 'LINE_'.$idx.':'.$err;
            }

            foreach ($this->validateAccountType($line->accountType) as $msg) {
                if (str_starts_with($msg, 'WARN:')) {
                    $warnings[] = 'LINE_'.$idx.':'.substr($msg, 5);
                } else {
                    $errors[] = 'LINE_'.$idx.':'.$msg;
                }
            }
        }

        if ($batch->openingDate) {
            try {
                Carbon::parse($batch->openingDate);
            } catch (\Throwable $e) {
                $errors[] = 'OPENING_DATE_INVALID';
            }
        }

        if ((bool) config('opening_balance.require_balanced_entry', true) && ! $batch->isBalanced()) {
            $errors[] = 'BATCH_NOT_BALANCED';
        }

        if (! (bool) config('opening_balance.allow_unbalanced_opening_balance', false) && ! $batch->isBalanced()) {
            $errors[] = 'BATCH_UNBALANCED_NOT_ALLOWED';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public function isBalanced(OpeningBalanceBatch $batch): bool
    {
        return $batch->isBalanced();
    }

    public function validateLine(OpeningBalanceLine $line): array
    {
        $errors = [];

        if ($line->hasBothDebitAndCredit()) {
            $errors[] = 'LINE_HAS_BOTH_DEBIT_AND_CREDIT';
        }

        if ($line->debitAmount() < 0) {
            $errors[] = 'LINE_DEBIT_NEGATIVE';
        }

        if ($line->creditAmount() < 0) {
            $errors[] = 'LINE_CREDIT_NEGATIVE';
        }

        return $errors;
    }

    /**
     * @return array<int, string> errors (or warnings prefixed with WARN:)
     */
    public function validateAccountType(?string $accountType): array
    {
        if ($accountType === null || trim($accountType) === '') {
            return ['WARN:ACCOUNT_TYPE_MISSING'];
        }

        $type = trim($accountType);

        $real = (array) config('opening_balance.real_account_types', []);
        $nominal = (array) config('opening_balance.nominal_account_types', []);

        if (in_array($type, $real, true)) {
            return [];
        }

        if (in_array($type, $nominal, true)) {
            return (bool) config('opening_balance.allow_nominal_accounts_opening_balance', false)
                ? ['WARN:NOMINAL_ACCOUNT_TYPE_USED']
                : ['NOMINAL_ACCOUNT_TYPE_NOT_ALLOWED'];
        }

        return ['WARN:UNKNOWN_ACCOUNT_TYPE'];
    }

    public function canUseAccountType(?string $accountType): bool
    {
        $res = $this->validateAccountType($accountType);
        foreach ($res as $msg) {
            if (! str_starts_with($msg, 'WARN:')) {
                return false;
            }
        }
        return true;
    }
}


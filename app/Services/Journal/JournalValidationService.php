<?php

namespace App\Services\Journal;

use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Department;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Project;

class JournalValidationService
{
    private const TOLERANCE = 0.0001;

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{valid:bool,errors:array,warnings:array,totals:array{debit:string,credit:string,difference:string}}
     */
    public function validateLines(array $lines, bool $requireActiveAccounts = true): array
    {
        $errors = [];
        $warnings = [];

        if (count($lines) < 2) {
            $errors['lines'][] = 'Minimal 2 lines required.';
            return $this->result(false, $errors, $warnings, $lines);
        }

        foreach ($lines as $i => $line) {
            $idx = $i + 1;

            $accountId = $line['account_id'] ?? null;
            if (! $accountId) {
                $errors["lines.$i.account_id"][] = "Line #$idx: account_id is required.";
            }

            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if ($debit < 0) {
                $errors["lines.$i.debit"][] = "Line #$idx: debit cannot be negative.";
            }
            if ($credit < 0) {
                $errors["lines.$i.credit"][] = "Line #$idx: credit cannot be negative.";
            }

            if ($debit > 0 && $credit > 0) {
                $errors["lines.$i"][] = "Line #$idx: cannot have both debit and credit.";
            }

            if ($debit == 0.0 && $credit == 0.0) {
                $errors["lines.$i"][] = "Line #$idx: debit and credit cannot both be zero.";
            }
        }

        if (! empty($errors)) {
            return $this->result(false, $errors, $warnings, $lines);
        }

        $accountValidation = $this->validateAccounts($lines, $requireActiveAccounts);
        if (! $accountValidation['valid']) {
            $errors = array_merge($errors, $accountValidation['errors']);
        }

        $dimensionValidation = $this->validateDimensions($lines);
        if (! $dimensionValidation['valid']) {
            $errors = array_merge($errors, $dimensionValidation['errors']);
        }

        $balance = $this->validateBalanced($lines);
        if (! $balance['valid']) {
            $errors = array_merge($errors, $balance['errors']);
        }

        return $this->result(empty($errors), $errors, $warnings, $lines);
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{valid:bool,errors:array,warnings:array}
     */
    public function validateDimensions(array $lines): array
    {
        $errors = [];
        $warnings = [];

        $departmentIds = [];
        $projectIds = [];

        foreach ($lines as $i => $line) {
            if (isset($line['department_id']) && $line['department_id']) {
                $departmentIds[] = (int) $line['department_id'];
            }
            if (isset($line['project_id']) && $line['project_id']) {
                $projectIds[] = (int) $line['project_id'];
            }
        }

        $departmentIds = array_values(array_unique($departmentIds));
        $projectIds = array_values(array_unique($projectIds));

        $departments = $departmentIds === []
            ? collect()
            : Department::query()->whereIn('id', $departmentIds)->get(['id', 'is_active'])->keyBy('id');

        $projects = $projectIds === []
            ? collect()
            : Project::query()->whereIn('id', $projectIds)->get(['id', 'is_active', 'status'])->keyBy('id');

        foreach ($lines as $i => $line) {
            $idx = $i + 1;

            $departmentId = $line['department_id'] ?? null;
            if ($departmentId) {
                $dept = $departments->get((int) $departmentId);
                if (! $dept) {
                    $errors["lines.$i.department_id"][] = "Line #$idx: department not found.";
                } elseif (! (bool) $dept->is_active) {
                    $errors["lines.$i.department_id"][] = "Line #$idx: department is inactive.";
                }
            }

            $projectId = $line['project_id'] ?? null;
            if ($projectId) {
                $project = $projects->get((int) $projectId);
                if (! $project) {
                    $errors["lines.$i.project_id"][] = "Line #$idx: project not found.";
                } elseif (! (bool) $project->is_active) {
                    $errors["lines.$i.project_id"][] = "Line #$idx: project is inactive.";
                } elseif ((string) $project->status !== 'active') {
                    $errors["lines.$i.project_id"][] = "Line #$idx: project is not usable for new journal lines.";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{valid:bool,errors:array,warnings:array}
     */
    public function validateBalanced(array $lines): array
    {
        $errors = [];
        $warnings = [];

        $debit = (float) $this->totalDebit($lines);
        $credit = (float) $this->totalCredit($lines);
        $diff = $debit - $credit;

        if (abs($diff) > self::TOLERANCE) {
            $errors['balance'][] = 'Total debit must equal total credit.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{valid:bool,errors:array,warnings:array}
     */
    public function validateAccounts(array $lines, bool $requireActive = true): array
    {
        $errors = [];
        $warnings = [];

        $ids = [];
        foreach ($lines as $line) {
            if (isset($line['account_id']) && $line['account_id']) {
                $ids[] = (int) $line['account_id'];
            }
        }

        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            $errors['accounts'][] = 'account_id is required.';
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        $accounts = ChartOfAccount::query()
            ->whereIn('id', $ids)
            ->get(['id', 'is_active'])
            ->keyBy('id');

        foreach ($ids as $id) {
            $acc = $accounts->get($id);
            if (! $acc) {
                $errors['accounts'][] = "Account not found: $id";
                continue;
            }
            if ($requireActive && ! (bool) $acc->is_active) {
                $errors['accounts'][] = "Account inactive: $id";
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     */
    public function totalDebit(array $lines): string
    {
        $sum = '0';
        foreach ($lines as $line) {
            $sum = $this->moneyAdd($sum, (string) ($line['debit'] ?? '0'));
        }
        return $sum;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     */
    public function totalCredit(array $lines): string
    {
        $sum = '0';
        foreach ($lines as $line) {
            $sum = $this->moneyAdd($sum, (string) ($line['credit'] ?? '0'));
        }
        return $sum;
    }

    public function validateCanPost(JournalEntry $journal): array
    {
        $errors = [];
        $warnings = [];

        if ($journal->isVoided()) {
            $errors['status'][] = 'Journal is void and cannot be posted.';
        }
        if ($journal->isPosted()) {
            $errors['status'][] = 'Journal is already posted.';
        }
        if ($journal->isObsolete()) {
            $errors['status'][] = 'Journal is obsolete and cannot be posted.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{valid:bool,errors:array,warnings:array,totals:array{debit:string,credit:string,difference:string}}
     */
    private function result(bool $valid, array $errors, array $warnings, array $lines): array
    {
        $debit = $this->totalDebit($lines);
        $credit = $this->totalCredit($lines);
        $difference = $this->moneySub($debit, $credit);

        return [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'totals' => [
                'debit' => $debit,
                'credit' => $credit,
                'difference' => $difference,
            ],
        ];
    }

    private function moneyAdd(string $a, string $b): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, 2);
        }
        return number_format(((float) $a) + ((float) $b), 2, '.', '');
    }

    private function moneySub(string $a, string $b): string
    {
        if (function_exists('bcsub')) {
            return bcsub($a, $b, 2);
        }
        return number_format(((float) $a) - ((float) $b), 2, '.', '');
    }
}

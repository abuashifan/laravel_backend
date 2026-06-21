<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\ChartOfAccount;

class ChartOfAccountService
{
    public function list(array $filters = [])
    {
        $query = ChartOfAccount::query()->with('parent');

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['account_types']) && is_array($filters['account_types'])) {
            $query->whereIn('account_type', array_values(array_filter(
                $filters['account_types'],
                fn (mixed $type): bool => is_string($type) && $type !== '',
            )));
        } elseif (! empty($filters['account_type'])) {
            $query->where('account_type', (string) $filters['account_type']);
        }

        if (array_key_exists('is_cash_bank', $filters)) {
            $query->where('is_cash_bank', (bool) $filters['is_cash_bank']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('account_code', 'like', $term)
                    ->orWhere('account_name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        return $query->orderBy('account_code')->get();
    }

    public function create(array $data): ChartOfAccount
    {
        $accountType = (string) $data['account_type'];
        $data['normal_balance'] = $this->validateNormalBalance($accountType, $data['normal_balance'] ?? null);

        $this->validateCashBank($accountType, (bool) ($data['is_cash_bank'] ?? false));

        $code = (string) $data['account_code'];
        if (ChartOfAccount::query()->where('account_code', $code)->exists()) {
            throw ApiException::make('DUPLICATE_ACCOUNT_CODE', 'Account code is already in use.', 422, [
                'account_code' => ['Account Code is already in use.'],
            ]);
        }

        if (! empty($data['parent_account_id'])) {
            if (! ChartOfAccount::query()->whereKey((int) $data['parent_account_id'])->exists()) {
                throw ApiException::make('PARENT_ACCOUNT_NOT_FOUND', 'Parent account not found.', 422);
            }
        }

        return ChartOfAccount::query()->create($data);
    }

    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        $accountType = (string) ($data['account_type'] ?? $account->account_type);

        if (array_key_exists('normal_balance', $data) || array_key_exists('account_type', $data)) {
            $data['normal_balance'] = $this->validateNormalBalance($accountType, $data['normal_balance'] ?? $account->normal_balance);
        }

        $this->validateCashBank($accountType, (bool) ($data['is_cash_bank'] ?? $account->is_cash_bank));

        if (! empty($data['account_code']) && $data['account_code'] !== $account->account_code) {
            if (ChartOfAccount::query()->where('account_code', (string) $data['account_code'])->exists()) {
                throw ApiException::make('DUPLICATE_ACCOUNT_CODE', 'Account code is already in use.', 422, [
                    'account_code' => ['Account Code is already in use.'],
                ]);
            }
        }

        if (array_key_exists('parent_account_id', $data)) {
            $parentId = $data['parent_account_id'];
            if ($parentId !== null && (int) $parentId === (int) $account->id) {
                throw ApiException::make('INVALID_PARENT_ACCOUNT', 'parent_account_id cannot be self.', 422);
            }
            if ($parentId !== null && ! ChartOfAccount::query()->whereKey((int) $parentId)->exists()) {
                throw ApiException::make('PARENT_ACCOUNT_NOT_FOUND', 'Parent account not found.', 422);
            }
        }

        $account->fill($data);
        $account->save();

        return $account->refresh();
    }

    public function deactivate(ChartOfAccount $account): ChartOfAccount
    {
        if ($account->children()->where('is_active', true)->exists()) {
            throw ApiException::make('ACCOUNT_HAS_ACTIVE_CHILDREN', 'Cannot deactivate account with active child accounts.', 422);
        }

        $account->is_active = false;
        $account->save();

        return $account->refresh();
    }

    public function activate(ChartOfAccount $account): ChartOfAccount
    {
        $account->is_active = true;
        $account->save();

        return $account->refresh();
    }

    public function validateNormalBalance(string $accountType, ?string $normalBalance): string
    {
        $accountType = trim($accountType);
        $normalBalance = $normalBalance ? trim($normalBalance) : null;

        if ($normalBalance && in_array($normalBalance, ['debit', 'credit'], true)) {
            return $normalBalance;
        }

        return match ($accountType) {
            'asset', 'expense' => 'debit',
            'liability', 'equity', 'revenue' => 'credit',
            default => 'debit',
        };
    }

    public function validateCashBank(string $accountType, bool $isCashBank): void
    {
        if ($isCashBank && $accountType !== 'asset') {
            throw ApiException::make('INVALID_CASH_BANK_ACCOUNT_TYPE', 'is_cash_bank is only allowed for asset accounts.', 422);
        }
    }
}

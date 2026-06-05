<?php

namespace App\Services\Settings;

use App\Models\Company;
use App\Models\CompanyAccountingSetting;
use App\Models\CompanyModuleSetting;

class CompanySettingService
{
    public function getOrCreateAccountingSetting(Company $company): CompanyAccountingSetting
    {
        return CompanyAccountingSetting::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'base_currency' => 'IDR',
                'default_payment_term_id' => null,
                'amount_precision' => 2,
                'quantity_precision' => 4,
                'rounding_method' => 'half_up',
                'transaction_workflow_mode' => 'simple_auto_post',
                'auto_post_transactions' => true,
                'allow_edit_transactions' => true,
                'allow_edit_posted_transactions' => true,
                'allow_void_transactions' => true,
                'hide_voided_transactions' => true,
                'require_void_reason' => true,
                'approval_enabled' => false,
                'tax_enabled' => false,
                'user_permission_mode' => 'role_template',
                'block_outside_current_fiscal_year' => true,
                'date_warning_enabled' => true,
                'allow_backdated_transactions' => true,
                'max_backdate_days' => null,
                'allow_future_transactions' => false,
                'max_future_days' => 0,
            ]
        );
    }

    public function getOrCreateModuleSetting(Company $company): CompanyModuleSetting
    {
        return CompanyModuleSetting::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'sales_enabled' => true,
                'purchase_enabled' => true,
                'cash_bank_enabled' => true,
                'inventory_enabled' => false,
                'warehouse_enabled' => false,
                'fixed_asset_enabled' => false,
                'approval_enabled' => false,
                'tax_enabled' => false,
                'reports_enabled' => true,
            ]
        );
    }

    public function getSettings(Company $company): array
    {
        $accounting = $this->getOrCreateAccountingSetting($company);
        $modules = $this->getOrCreateModuleSetting($company);

        return [
            'accounting' => $accounting->toArray(),
            'transaction_defaults' => [
                'default_payment_term_id' => $accounting->default_payment_term_id,
            ],
            'modules' => $modules->toArray(),
        ];
    }

    public function updateAccountingSetting(Company $company, array $data): CompanyAccountingSetting
    {
        $accounting = $this->getOrCreateAccountingSetting($company);
        $modules = $this->getOrCreateModuleSetting($company);

        $accounting->fill($data);

        $this->normalizeConsistency($accounting, $modules);

        $accounting->save();
        $modules->save();

        return $accounting->refresh();
    }

    public function updateModuleSetting(Company $company, array $data): CompanyModuleSetting
    {
        $accounting = $this->getOrCreateAccountingSetting($company);
        $modules = $this->getOrCreateModuleSetting($company);

        $modules->fill($data);

        $this->normalizeConsistency($accounting, $modules);

        $accounting->save();
        $modules->save();

        return $modules->refresh();
    }

    public function updateTransactionDefaults(Company $company, array $data): array
    {
        $accounting = $this->getOrCreateAccountingSetting($company);
        $accounting->fill([
            'default_payment_term_id' => $data['default_payment_term_id'] ?? null,
        ]);
        $accounting->save();

        return [
            'default_payment_term_id' => $accounting->refresh()->default_payment_term_id,
        ];
    }

    private function normalizeConsistency(
        CompanyAccountingSetting $accounting,
        CompanyModuleSetting $modules
    ): void {
        // 1) workflow mode implies auto-post
        if ($accounting->transaction_workflow_mode === 'simple_auto_post') {
            $accounting->auto_post_transactions = true;
        }

        // 2) workflow mode draft_approve_post implies approval enabled
        if ($accounting->transaction_workflow_mode === 'draft_approve_post') {
            $accounting->approval_enabled = true;
        }

        // 3) approval disabled cannot keep draft_approve_post
        if ($accounting->approval_enabled === false && $accounting->transaction_workflow_mode === 'draft_approve_post') {
            $accounting->transaction_workflow_mode = 'draft_then_post';
        }

        // 4) keep tax consistency
        if ($modules->isDirty('tax_enabled') && ! $accounting->isDirty('tax_enabled')) {
            $accounting->tax_enabled = (bool) $modules->tax_enabled;
        } elseif ($accounting->isDirty('tax_enabled') && ! $modules->isDirty('tax_enabled')) {
            $modules->tax_enabled = (bool) $accounting->tax_enabled;
        } else {
            $modules->tax_enabled = (bool) $accounting->tax_enabled;
        }

        // 5) keep approval consistency
        if ($modules->isDirty('approval_enabled') && ! $accounting->isDirty('approval_enabled')) {
            $accounting->approval_enabled = (bool) $modules->approval_enabled;
        } elseif ($accounting->isDirty('approval_enabled') && ! $modules->isDirty('approval_enabled')) {
            $modules->approval_enabled = (bool) $accounting->approval_enabled;
        } else {
            $modules->approval_enabled = (bool) $accounting->approval_enabled;
        }

        // if approval ended up disabled, enforce workflow rule again
        if ($accounting->approval_enabled === false && $accounting->transaction_workflow_mode === 'draft_approve_post') {
            $accounting->transaction_workflow_mode = 'draft_then_post';
        }
    }
}

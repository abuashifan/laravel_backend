<?php

namespace App\Modules\Settings\Requests;

use App\Shared\Subscription\PlanPermissionResolver;
use App\Shared\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCompanyAccountingSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_currency' => ['nullable', 'string', 'size:3'],
            'default_payment_term_id' => ['nullable', 'integer', 'exists:tenant.payment_terms,id'],
            'amount_precision' => ['nullable', 'integer', 'min:0', 'max:6'],
            'quantity_precision' => ['nullable', 'integer', 'min:0', 'max:8'],
            'rounding_method' => ['nullable', 'in:half_up,half_down,bankers,floor,ceil'],
            'transaction_workflow_mode' => ['nullable', 'in:simple_auto_post,draft_then_post,draft_approve_post'],
            'auto_post_transactions' => ['nullable', 'boolean'],
            'allow_edit_transactions' => ['nullable', 'boolean'],
            'allow_edit_posted_transactions' => ['nullable', 'boolean'],
            'allow_void_transactions' => ['nullable', 'boolean'],
            'hide_voided_transactions' => ['nullable', 'boolean'],
            'require_void_reason' => ['nullable', 'boolean'],
            'approval_enabled' => ['nullable', 'boolean'],
            'tax_enabled' => ['nullable', 'boolean'],
            'user_permission_mode' => ['nullable', 'in:role_template,manual_per_user'],
            'block_outside_current_fiscal_year' => ['nullable', 'boolean'],
            'date_warning_enabled' => ['nullable', 'boolean'],
            'allow_backdated_transactions' => ['nullable', 'boolean'],
            'max_backdate_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'allow_future_transactions' => ['nullable', 'boolean'],
            'max_future_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mode = $this->input('transaction_workflow_mode');
            $autoPost = $this->input('auto_post_transactions');
            $approval = $this->input('approval_enabled');

            if ($mode === 'simple_auto_post' && $autoPost === false) {
                $validator->errors()->add('auto_post_transactions', 'simple_auto_post tidak boleh auto_post_transactions false.');
            }

            if ($mode === 'draft_approve_post' && $approval === false) {
                $validator->errors()->add('approval_enabled', 'draft_approve_post harus approval_enabled true.');
            }

            if ($approval === false && $mode === 'draft_approve_post') {
                $validator->errors()->add('transaction_workflow_mode', 'approval_enabled false tidak boleh transaction_workflow_mode draft_approve_post.');
            }

            // Pengaturan bertingkat, bukan izin — digerbangi di validasi request,
            // bukan di peta izin (`config/plan_features.php`). Perusahaan yang
            // SUDAH memakai draft_approve_post lalu turun tier tidak dipaksa
            // berpindah; yang ditahan hanya memilihnya lagi dari sini.
            if ($mode === 'draft_approve_post' && ! $this->planAllowsTransactionApproval()) {
                $validator->errors()->add(
                    'transaction_workflow_mode',
                    'Alur persetujuan transaksi (draft_approve_post) memerlukan paket Pro ke atas.'
                );
            }
        });
    }

    private function planAllowsTransactionApproval(): bool
    {
        $resolver = app(PlanPermissionResolver::class);

        // Saklar mati → perilaku identik dengan sebelum Fase 2 ada, sama
        // seperti janji `blockedKeysFor()`. Tanpa baris ini, gate BARU ini
        // (tidak lewat `blockedKeysFor()`) akan tetap menahan Basic bahkan
        // saat `plan_features.enforce = false`.
        if (! $resolver->enforcing()) {
            return true;
        }

        $company = app(TenantContext::class)->company();

        if (! $company) {
            return false;
        }

        return in_array('transaction_approval', $resolver->featuresFor($company), true);
    }
}

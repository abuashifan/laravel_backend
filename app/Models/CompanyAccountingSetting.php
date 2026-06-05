<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAccountingSetting extends Model
{
    use HasFactory;

    protected $table = 'company_accounting_settings';

    protected $fillable = [
        'company_id',
        'base_currency',
        'default_payment_term_id',
        'amount_precision',
        'quantity_precision',
        'rounding_method',
        'transaction_workflow_mode',
        'auto_post_transactions',
        'allow_edit_transactions',
        'allow_edit_posted_transactions',
        'allow_void_transactions',
        'hide_voided_transactions',
        'require_void_reason',
        'approval_enabled',
        'tax_enabled',
        'user_permission_mode',
        'block_outside_current_fiscal_year',
        'date_warning_enabled',
        'allow_backdated_transactions',
        'max_backdate_days',
        'allow_future_transactions',
        'max_future_days',
    ];

    protected $casts = [
        'amount_precision' => 'integer',
        'quantity_precision' => 'integer',
        'default_payment_term_id' => 'integer',
        'auto_post_transactions' => 'boolean',
        'allow_edit_transactions' => 'boolean',
        'allow_edit_posted_transactions' => 'boolean',
        'allow_void_transactions' => 'boolean',
        'hide_voided_transactions' => 'boolean',
        'require_void_reason' => 'boolean',
        'approval_enabled' => 'boolean',
        'tax_enabled' => 'boolean',
        'user_permission_mode' => 'string',
        'block_outside_current_fiscal_year' => 'boolean',
        'date_warning_enabled' => 'boolean',
        'allow_backdated_transactions' => 'boolean',
        'max_backdate_days' => 'integer',
        'allow_future_transactions' => 'boolean',
        'max_future_days' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

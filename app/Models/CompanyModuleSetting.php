<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyModuleSetting extends Model
{
    use HasFactory;

    protected $table = 'company_module_settings';

    protected $fillable = [
        'company_id',
        'sales_enabled',
        'purchase_enabled',
        'cash_bank_enabled',
        'inventory_enabled',
        'warehouse_enabled',
        'fixed_asset_enabled',
        'approval_enabled',
        'tax_enabled',
        'reports_enabled',
    ];

    protected $casts = [
        'sales_enabled' => 'boolean',
        'purchase_enabled' => 'boolean',
        'cash_bank_enabled' => 'boolean',
        'inventory_enabled' => 'boolean',
        'warehouse_enabled' => 'boolean',
        'fixed_asset_enabled' => 'boolean',
        'approval_enabled' => 'boolean',
        'tax_enabled' => 'boolean',
        'reports_enabled' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}


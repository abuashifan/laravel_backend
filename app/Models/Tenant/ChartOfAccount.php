<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'account_code',
        'account_name',
        'account_type',
        'parent_account_id',
        'normal_balance',
        'cash_flow_section',
        'is_cash_bank',
        'is_active',
        'is_system_default',
        'description',
        'metadata',
    ];

    protected $casts = [
        'parent_account_id' => 'integer',
        'is_cash_bank' => 'boolean',
        'is_active' => 'boolean',
        'is_system_default' => 'boolean',
        'metadata' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function accountMappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class, 'account_id');
    }

    public function productSalesAccounts(): HasMany
    {
        return $this->hasMany(Product::class, 'sales_account_id');
    }

    public function productPurchaseAccounts(): HasMany
    {
        return $this->hasMany(Product::class, 'purchase_account_id');
    }

    public function productInventoryAccounts(): HasMany
    {
        return $this->hasMany(Product::class, 'inventory_account_id');
    }

    public function productCogsAccounts(): HasMany
    {
        return $this->hasMany(Product::class, 'cogs_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function isAsset(): bool
    {
        return $this->account_type === 'asset';
    }

    public function isLiability(): bool
    {
        return $this->account_type === 'liability';
    }

    public function isEquity(): bool
    {
        return $this->account_type === 'equity';
    }

    public function isRevenue(): bool
    {
        return $this->account_type === 'revenue';
    }

    public function isExpense(): bool
    {
        return $this->account_type === 'expense';
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    public function isCreditNormal(): bool
    {
        return $this->normal_balance === 'credit';
    }

    public function isCashBank(): bool
    {
        return (bool) $this->is_cash_bank;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}


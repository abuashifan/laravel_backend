<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'products';

    protected $fillable = [
        'product_code',
        'product_name',
        'product_type',
        'product_category_id',
        'unit_id',
        'is_stock_item',
        'is_active',
        'description',
        'metadata',
        'sales_account_id',
        'purchase_account_id',
        'inventory_account_id',
        'cogs_account_id',
    ];

    protected $casts = [
        'product_category_id' => 'integer',
        'unit_id' => 'integer',
        'is_stock_item' => 'boolean',
        'is_active' => 'boolean',
        'sales_account_id' => 'integer',
        'purchase_account_id' => 'integer',
        'inventory_account_id' => 'integer',
        'cogs_account_id' => 'integer',
        'metadata' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'sales_account_id');
    }

    public function purchaseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'purchase_account_id');
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'inventory_account_id');
    }

    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cogs_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function isGoods(): bool
    {
        return $this->product_type === 'goods';
    }

    public function isService(): bool
    {
        return $this->product_type === 'service';
    }

    public function isNonInventory(): bool
    {
        return $this->product_type === 'non_inventory';
    }

    public function isStockItem(): bool
    {
        return (bool) $this->is_stock_item;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}


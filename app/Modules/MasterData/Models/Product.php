<?php

namespace App\Modules\MasterData\Models;

use Database\Factories\Tenant\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return ProductFactory::new();
    }

    protected $connection = 'tenant';

    protected $table = 'products';

    protected $fillable = [
        'product_code',
        'product_name',
        'product_type',
        'product_category_id',
        'unit_id',
        'is_stock_item',
        'min_stock',
        'purchase_price',
        'selling_price',
        'is_active',
        'description',
        'metadata',
        'sales_account_id',
        'sales_discount_account_id',
        'sales_return_account_id',
        'purchase_return_account_id',
        'inventory_account_id',
        'inventory_interim_account_id',
        'cogs_account_id',
    ];

    protected $casts = [
        'product_category_id' => 'integer',
        'unit_id' => 'integer',
        'is_stock_item' => 'boolean',
        'min_stock' => 'float',
        'purchase_price' => 'float',
        'selling_price' => 'float',
        'is_active' => 'boolean',
        'sales_account_id' => 'integer',
        'sales_discount_account_id' => 'integer',
        'sales_return_account_id' => 'integer',
        'purchase_return_account_id' => 'integer',
        'inventory_account_id' => 'integer',
        'inventory_interim_account_id' => 'integer',
        'cogs_account_id' => 'integer',
        'metadata' => 'array',
    ];

    // Kolom harga NOT NULL: kiriman kosong (null) dianggap 0.
    public function setPurchasePriceAttribute(mixed $value): void
    {
        $this->attributes['purchase_price'] = $value ?? 0;
    }

    public function setSellingPriceAttribute(mixed $value): void
    {
        $this->attributes['selling_price'] = $value ?? 0;
    }

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

    public function salesDiscountAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'sales_discount_account_id');
    }

    public function salesReturnAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'sales_return_account_id');
    }

    public function purchaseReturnAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'purchase_return_account_id');
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'inventory_account_id');
    }

    public function inventoryInterimAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'inventory_interim_account_id');
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

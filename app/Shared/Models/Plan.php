<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    /**
     * Tier yang jumlah perusahaannya ditentukan per client, bukan oleh paket.
     * Hanya tier ini yang membaca `users.company_quota`.
     */
    public const CUSTOM_CODE = 'custom';

    protected $fillable = [
        'name',
        'code',
        'description',
        'max_users',
        'max_companies',
        'max_transactions_per_month',
        'storage_quota_mb',
        'import_retention_days',
        'can_use_sales',
        'can_use_purchases',
        'can_use_inventory',
        'can_export_reports',
        'monthly_price',
        'yearly_price',
        'status',
        'features',
    ];

    protected $casts = [
        'can_use_sales' => 'boolean',
        'can_use_purchases' => 'boolean',
        'can_use_inventory' => 'boolean',
        'can_export_reports' => 'boolean',
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'features' => 'array',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isCustom(): bool
    {
        return $this->code === self::CUSTOM_CODE;
    }
}

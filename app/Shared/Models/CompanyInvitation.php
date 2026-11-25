<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        // Aplikasi asal undangan (`pos` = aplikasi kasir); null = Finlite Accounting.
        'product',
        'email',
        'role',
        'full_access',
        'permissions',
        'token',
        'status',
        'invited_by',
        'accepted_at',
        'expires_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
        'full_access' => 'boolean',
        'permissions' => 'array',
    ];

    public function scopeWithoutWorkspace(Builder $query): Builder
    {
        return $query->where(fn (Builder $product) => $product->whereNull('product')
            ->orWhere('product', '<>', TenantDatabase::PRODUCT_WORKSPACE));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}

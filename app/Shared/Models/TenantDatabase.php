<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDatabase extends Model
{
    use HasFactory;

    public const PRODUCT_ACCOUNTING = 'accounting';

    public const PRODUCT_POS = 'pos';

    /** Nama global scope yang membatasi kueri ke tenant akuntansi. */
    public const ACCOUNTING_SCOPE = 'accounting_product';

    protected $fillable = [
        'company_id',
        'product',
        'database_name',
        'database_path',
        'driver',
        'status',
        'migration_version',
        'last_migrated_at',
        'last_backup_at',
        'size_bytes',
        'measured_at',
        'metadata',
    ];

    protected $casts = [
        'last_migrated_at' => 'datetime',
        'last_backup_at' => 'datetime',
        'measured_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Seluruh kode lama (middleware, job, command, test) memperlakukan
     * "tenant perusahaan" sebagai tenant akuntansi. Scope ini menjaga kueri
     * `where('company_id', ...)->first()` di sana tidak tiba-tiba mengambil
     * tenant POS. Kode produk lain memakai `forProduct()`.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::ACCOUNTING_SCOPE, function (Builder $query): void {
            $query->where($query->qualifyColumn('product'), self::PRODUCT_ACCOUNTING);
        });
    }

    /** Kueri tenant untuk produk tertentu, tanpa scope akuntansi bawaan. */
    public static function forProduct(string $product): Builder
    {
        return static::withoutGlobalScope(self::ACCOUNTING_SCOPE)
            ->where('product', $product);
    }

    /** Kueri semua tenant dari semua produk. */
    public static function allProducts(): Builder
    {
        return static::withoutGlobalScope(self::ACCOUNTING_SCOPE);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

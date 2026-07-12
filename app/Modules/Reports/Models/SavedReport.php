<?php

namespace App\Modules\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laporan Tersimpan (Fase 13). Menyimpan kombinasi (report_key + params) dengan
 * nama, milik satu user, dapat dibagikan ke banyak user lewat `saved_report_shares`.
 */
class SavedReport extends Model
{
    protected $connection = 'tenant';

    protected $table = 'saved_reports';

    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
    ];

    /**
     * report_key yang boleh disimpan — sepadan dengan katalog laporan frontend.
     * Whitelist mencegah penyimpanan kunci sembarang.
     */
    public const ALLOWED_REPORT_KEYS = [
        'general-ledger',
        'general-ledger-detail',
        'trial-balance',
        'profit-loss',
        'balance-sheet',
        'cash-flow',
        'cash-flow-direct',
        'retained-earnings',
        'equity-changes',
        'financial-summary',
        'journals',
        'account-ledger',
        'account-statement',
        'ar-aging',
        'ap-aging',
        'ar-outstanding',
        'ap-outstanding',
        'ar-customer-summary',
        'ap-vendor-summary',
        'stock',
        'inventory-analysis',
        'inventory-aging',
        'inventory-opname',
        'sales-summary',
        'sales-by-customer',
        'sales-by-product',
        'purchase-summary',
        'purchase-by-vendor',
        'purchase-by-product',
        'output-vat',
        'input-vat',
    ];

    public function shares(): HasMany
    {
        return $this->hasMany(SavedReportShare::class, 'saved_report_id');
    }
}

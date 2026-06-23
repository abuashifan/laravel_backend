<?php

namespace App\Models\Tenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorBill extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'vendor_bills';
    protected $guarded = [];
    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'is_taxable' => 'boolean',
        'tax_included' => 'boolean',
        'metadata' => 'array',
        'ap_account_id' => 'integer',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function lines(): HasMany { return $this->hasMany(VendorBillLine::class, 'vendor_bill_id')->orderBy('sort_order'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Contact::class, 'vendor_id'); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function goodsReceipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id'); }
    public function paymentTerm(): BelongsTo { return $this->belongsTo(PaymentTerm::class, 'payment_term_id'); }
    public function buyer(): BelongsTo { return $this->belongsTo(User::class, 'buyer_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function apAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'ap_account_id'); }
}

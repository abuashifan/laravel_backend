<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningBalanceBatch extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'opening_balance_batches';
    protected $guarded = [];

    protected $casts = [
        'opening_date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'difference' => 'decimal:2',
        'validated_at' => 'datetime',
        'posted_at' => 'datetime',
        'locked_at' => 'datetime',
        'reopened_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class, 'opening_balance_batch_id')->orderBy('id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function editable(): bool
    {
        return in_array((string) $this->status, ['draft', 'reopened'], true);
    }

    public function postedOrLocked(): bool
    {
        return in_array((string) $this->status, ['posted', 'locked'], true);
    }
}

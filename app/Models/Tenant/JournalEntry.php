<?php

namespace App\Models\Tenant;

use App\Traits\HasReportVisibility;
use App\Traits\HasRevisionTracking;
use App\Traits\HasSourceLink;
use App\Traits\HasTransactionLifecycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasFactory;
    use HasTransactionLifecycle;
    use HasRevisionTracking;
    use HasSourceLink, HasReportVisibility {
        HasSourceLink::scopeNotObsolete insteadof HasReportVisibility;
        HasSourceLink::scopeObsolete insteadof HasReportVisibility;
    }

    protected $connection = 'tenant';

    protected $table = 'journal_entries';

    protected $fillable = [
        'journal_number',
        'journal_date',
        'description',
        'status',
        'revision_no',
        'source_type',
        'source_id',
        'source_number',
        'source_revision',
        'source_module',
        'source_batch_id',
        'is_system_generated',
        'is_obsolete',
        'created_by',
        'updated_by',
        'approved_by',
        'posted_by',
        'voided_by',
        'approved_at',
        'posted_at',
        'voided_at',
        'void_reason',
        'edit_reason',
        'metadata',
    ];

    protected $casts = [
        'journal_date' => 'date',
        'revision_no' => 'integer',
        'source_revision' => 'integer',
        'is_system_generated' => 'boolean',
        'is_obsolete' => 'boolean',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id')->orderBy('line_order');
    }

    /**
     * Compatibility for TransactionPolicyService (expects `transaction_date`).
     */
    public function getTransactionDateAttribute(): ?string
    {
        $val = $this->journal_date;
        return $val ? (string) $val : null;
    }

    public function isManual(): bool
    {
        return ! $this->isSystemGenerated();
    }

    public function canBeEditedDirectly(): bool
    {
        return ! $this->isSystemGenerated();
    }
}

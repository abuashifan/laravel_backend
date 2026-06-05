<?php

namespace App\Models\Tenant;

use App\Support\Revision\TransactionRevisionAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionRevision extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'transaction_revisions';

    protected $fillable = [
        'source_type',
        'source_id',
        'source_number',
        'source_module',
        'source_revision_from',
        'source_revision_to',
        'action',
        'reason',
        'old_values',
        'new_values',
        'changed_fields',
        'edited_by',
        'edited_at',
        'metadata',
    ];

    protected $casts = [
        'source_revision_from' => 'integer',
        'source_revision_to' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_fields' => 'array',
        'edited_by' => 'integer',
        'edited_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function isEdit(): bool
    {
        return $this->action === TransactionRevisionAction::EDIT;
    }

    public function isVoid(): bool
    {
        return $this->action === TransactionRevisionAction::VOID;
    }

    public function isCorrection(): bool
    {
        return $this->action === TransactionRevisionAction::CORRECTION;
    }

    public function hasChangedField(string $field): bool
    {
        $changed = (array) ($this->changed_fields ?? []);
        return array_key_exists($field, $changed);
    }
}


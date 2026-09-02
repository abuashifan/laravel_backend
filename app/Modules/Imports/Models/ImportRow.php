<?php

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    protected $connection = 'tenant';

    protected $table = 'import_rows';

    protected $fillable = [
        'import_batch_id',
        'profile',
        'row_number',
        'raw',
        'normalized',
        'status',
        'errors',
        'warnings',
        'document_id',
        'document_type',
        'external_ref',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'raw' => 'array',
        'normalized' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
        'document_id' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}

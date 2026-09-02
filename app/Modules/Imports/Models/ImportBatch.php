<?php

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $connection = 'tenant';

    protected $table = 'import_batches';

    protected $fillable = [
        'uuid',
        'profile',
        'original_filename',
        'stored_path',
        'file_hash',
        'column_map',
        'status',
        'total_rows',
        'valid_rows',
        'failed_rows',
        'warning_rows',
        'committed_rows',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'column_map' => 'array',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'failed_rows' => 'integer',
        'warning_rows' => 'integer',
        'committed_rows' => 'integer',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_batch_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', (array) config('imports.active_statuses', []));
    }
}

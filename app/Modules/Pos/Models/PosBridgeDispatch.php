<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosBridgeDispatch extends Model
{
    public const STATUSES = ['pending', 'sent', 'failed', 'held', 'skipped'];

    protected $connection = 'pos';

    protected $table = 'pos_bridge_dispatches';

    protected $fillable = [
        'kind', 'source_id', 'source_label', 'entry_date', 'status', 'attempts',
        'journal_id', 'journal_number', 'error', 'summary', 'sent_at',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'entry_date' => 'date:Y-m-d',
        'attempts' => 'integer',
        'journal_id' => 'integer',
        'summary' => 'array',
        'sent_at' => 'datetime',
    ];
}

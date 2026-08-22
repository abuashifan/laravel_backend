<?php

namespace App\Modules\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedReportShare extends Model
{
    protected $connection = 'tenant';

    protected $table = 'saved_report_shares';

    protected $guarded = [];

    public function savedReport(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class, 'saved_report_id');
    }
}

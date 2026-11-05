<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosCategory extends Model
{
    protected $connection = 'tenant';

    protected $table = 'pos_categories';

    protected $fillable = ['name'];

    protected $casts = [];

    public function products(): HasMany
    {
        return $this->hasMany(PosProduct::class, 'category_id');
    }
}

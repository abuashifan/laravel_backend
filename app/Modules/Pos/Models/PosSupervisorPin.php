<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosSupervisorPin extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_supervisor_pins';

    protected $fillable = ['pin_hash', 'set_by', 'failed_attempts', 'locked_until'];

    protected $hidden = ['pin_hash'];

    protected $casts = ['set_by' => 'integer', 'failed_attempts' => 'integer', 'locked_until' => 'datetime'];
}

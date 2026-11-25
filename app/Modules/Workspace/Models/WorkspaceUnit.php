<?php

namespace App\Modules\Workspace\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceUnit extends Model
{
    protected $connection = 'workspace';

    protected $table = 'workspace_units';

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyUserPermissionOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_user_id',
        'permission_id',
        'effect',
        'reason',
        'created_by',
        'updated_by',
    ];

    public function companyUser(): BelongsTo
    {
        return $this->belongsTo(CompanyUser::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyUser extends Model
{
    use HasFactory;

    protected $table = 'company_users';

    protected $fillable = [
        'company_id',
        'user_id',
        'role',
        'role_id',
        'status',
        'joined_at',
        'last_accessed_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rolePreset(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(CompanyUserPermissionOverride::class);
    }
}

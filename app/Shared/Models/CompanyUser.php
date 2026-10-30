<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyUser extends Model
{
    use HasFactory;

    protected $table = 'company_users';

    protected $fillable = [
        'company_id',
        'user_id',
        'role',
        'full_access',
        'is_owner',
        'status',
        'joined_at',
        'last_accessed_at',
    ];

    protected $casts = [
        'full_access' => 'boolean',
        'is_owner' => 'boolean',
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

    public function assignedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'company_user_permissions')->withTimestamps();
    }
}

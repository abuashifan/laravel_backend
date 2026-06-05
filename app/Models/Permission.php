<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'module',
        'group',
        'feature',
        'action',
        'label',
        'description',
        'matrix_column',
        'is_special',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_special' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withTimestamps();
    }
}

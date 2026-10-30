<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}

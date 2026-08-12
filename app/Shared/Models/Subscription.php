<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris per periode langganan seorang client. Perpanjangan membuat baris
 * BARU (mulai di `ends_at` baris sebelumnya), bukan menggeser tanggal baris
 * lama — riwayat penagihan terbentuk sendiri. Lihat `SubscriptionService`
 * untuk satu-satunya jalur penulisan yang sah.
 *
 * `status` sengaja TIDAK ada di sini — keadaan langganan diturunkan dari
 * `starts_at`/`ends_at`/`cancelled_at`, bukan disimpan. Lihat
 * `SubscriptionService::stateFor()`.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_id',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'billing_cycle',
        'price',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'price' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}

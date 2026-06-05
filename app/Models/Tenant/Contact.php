<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'contacts';

    protected $fillable = [
        'contact_code',
        'name',
        'contact_type',
        'payment_term_id',
        'is_customer',
        'is_supplier',
        'is_employee',
        'phone',
        'email',
        'address',
        'tax_number',
        'notes',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_customer' => 'boolean',
        'is_supplier' => 'boolean',
        'is_employee' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function isCustomer(): bool
    {
        return (bool) $this->is_customer;
    }

    public function isSupplier(): bool
    {
        return (bool) $this->is_supplier;
    }

    public function isEmployee(): bool
    {
        return (bool) $this->is_employee;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}

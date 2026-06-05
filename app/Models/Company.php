<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'code',
        'email',
        'phone',
        'address',
        'city',
        'province',
        'postal_code',
        'country',
        'tax_number',
        'business_type',
        'logo',
        'status',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->withPivot(['role', 'status', 'joined_at', 'last_accessed_at'])
            ->withTimestamps();
    }

    public function companyUsers(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public function tenantDatabase(): HasOne
    {
        return $this->hasOne(TenantDatabase::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['trial', 'active'])
            ->latestOfMany();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    public function accountingSetting(): HasOne
    {
        return $this->hasOne(CompanyAccountingSetting::class);
    }

    public function moduleSetting(): HasOne
    {
        return $this->hasOne(CompanyModuleSetting::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function activeFiscalYear(): HasOne
    {
        return $this->hasOne(FiscalYear::class)->where('is_active', true);
    }

    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function documentNumberingSettings(): HasMany
    {
        return $this->hasMany(DocumentNumberingSetting::class);
    }

    public function documentNumberSequences(): HasMany
    {
        return $this->hasMany(DocumentNumberSequence::class);
    }
}

<?php

namespace App\Traits;

use App\Support\Transaction\TransactionStatus;

trait HasTransactionLifecycle
{
    public function scopeVisible($query)
    {
        return $query->where('status', '!=', TransactionStatus::VOID);
    }

    public function scopeVoided($query)
    {
        return $query->where('status', TransactionStatus::VOID);
    }

    public function scopePosted($query)
    {
        return $query->where('status', TransactionStatus::POSTED);
    }

    public function scopeNotVoided($query)
    {
        return $query->where('status', '!=', TransactionStatus::VOID);
    }

    public function isDraft(): bool
    {
        return $this->status === TransactionStatus::DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === TransactionStatus::APPROVED;
    }

    public function isPosted(): bool
    {
        return $this->status === TransactionStatus::POSTED;
    }

    public function isVoided(): bool
    {
        return $this->status === TransactionStatus::VOID;
    }
}


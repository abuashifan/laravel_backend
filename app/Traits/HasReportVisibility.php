<?php

namespace App\Traits;

use App\Services\Reports\ReportVisibilityService;

trait HasReportVisibility
{
    public function scopeVisibleForClient($query)
    {
        return $query->where('status', '!=', 'void');
    }

    public function scopeWithVoided($query)
    {
        return $query;
    }

    public function scopeReportableTransaction($query)
    {
        return $query->where('status', '=', 'posted');
    }

    public function scopeReportableJournal($query)
    {
        return $query->where('status', '=', 'posted')->where('is_obsolete', '=', false);
    }

    public function scopeReportableEffect($query)
    {
        return $query->where('status', '=', 'posted')->where('is_obsolete', '=', false);
    }

    public function scopeNotObsolete($query)
    {
        return $query->where('is_obsolete', '=', false);
    }

    public function scopeObsolete($query)
    {
        return $query->where('is_obsolete', '=', true);
    }

    private function reportVisibilityService(): ?ReportVisibilityService
    {
        try {
            return app(ReportVisibilityService::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isVisibleForClient(bool $includeVoid = false): bool
    {
        $service = $this->reportVisibilityService();
        if ($service) {
            return $service->isTransactionVisible((string) ($this->status ?? null), $includeVoid);
        }

        if (! isset($this->status)) {
            return false;
        }

        if ($includeVoid) {
            return in_array((string) $this->status, ['draft', 'approved', 'posted', 'void'], true);
        }

        return in_array((string) $this->status, ['draft', 'approved', 'posted'], true);
    }

    public function isReportableTransaction(): bool
    {
        $service = $this->reportVisibilityService();
        return $service ? $service->isTransactionReportable((string) ($this->status ?? null)) : ((string) ($this->status ?? '') === 'posted');
    }

    public function isReportableJournal(): bool
    {
        $service = $this->reportVisibilityService();
        $isObsolete = (bool) ($this->is_obsolete ?? false);

        return $service
            ? $service->isJournalReportable((string) ($this->status ?? null), $isObsolete)
            : ((string) ($this->status ?? '') === 'posted' && ! $isObsolete);
    }

    public function isReportableEffect(): bool
    {
        return $this->isReportableJournal();
    }

    public function isAuditVisible(): bool
    {
        $service = $this->reportVisibilityService();
        return $service ? $service->isVisibleInAudit((string) ($this->status ?? null), (bool) ($this->is_obsolete ?? false)) : isset($this->status);
    }

    public function isRevisionVisible(): bool
    {
        $service = $this->reportVisibilityService();
        return $service ? $service->isVisibleInRevision((string) ($this->status ?? null), (bool) ($this->is_obsolete ?? false)) : isset($this->status);
    }
}


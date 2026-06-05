<?php

namespace App\Traits;

use App\Support\SourceLink\SourceLink;

trait HasSourceLink
{
    public function scopeForSource($query, string $sourceType, int|string $sourceId)
    {
        return $query->where('source_type', $sourceType)->where('source_id', $sourceId);
    }

    public function scopeForSourceNumber($query, string $sourceNumber)
    {
        return $query->where('source_number', $sourceNumber);
    }

    public function scopeForSourceRevision($query, int $revision)
    {
        return $query->where('source_revision', $revision);
    }

    public function scopeForSourceModule($query, string $module)
    {
        return $query->where('source_module', $module);
    }

    public function scopeForSourceBatch($query, string $batchId)
    {
        return $query->where('source_batch_id', $batchId);
    }

    public function scopeSystemGenerated($query)
    {
        return $query->where('is_system_generated', true);
    }

    public function scopeNotObsolete($query)
    {
        return $query->where('is_obsolete', false);
    }

    public function scopeObsolete($query)
    {
        return $query->where('is_obsolete', true);
    }

    public function sourceLink(): SourceLink
    {
        return SourceLink::make(
            (string) ($this->source_type ?? ''),
            $this->source_id ?? null,
            $this->source_number ?? null,
            isset($this->source_revision) ? (int) $this->source_revision : null,
            $this->source_module ?? null,
            $this->source_batch_id ?? null,
            (bool) ($this->is_system_generated ?? false),
            (bool) ($this->is_obsolete ?? false),
            (array) ($this->metadata ?? [])
        );
    }

    public function isSystemGenerated(): bool
    {
        return (bool) ($this->is_system_generated ?? false);
    }

    public function isObsolete(): bool
    {
        return (bool) ($this->is_obsolete ?? false);
    }

    public function markAsObsolete(): bool
    {
        $this->is_obsolete = true;
        return (bool) $this->save();
    }

    public function belongsToSource(string $sourceType, int|string $sourceId): bool
    {
        return ($this->source_type ?? null) === $sourceType && ($this->source_id ?? null) === $sourceId;
    }
}


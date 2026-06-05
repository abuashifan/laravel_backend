<?php

namespace App\Traits;

trait HasRevisionTracking
{
    public function currentRevision(): int
    {
        $value = $this->revision_no ?? null;
        if ($value === null && method_exists($this, 'getAttribute')) {
            $value = $this->getAttribute('revision_no');
        }

        return $value !== null ? max(1, (int) $value) : 1;
    }

    public function nextRevision(): int
    {
        return $this->currentRevision() + 1;
    }

    public function incrementRevision(): int
    {
        $next = $this->nextRevision();
        $this->revision_no = $next;
        return $next;
    }
}


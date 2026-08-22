<?php

namespace App\Modules\MasterData\Services\Concerns;

trait ParsesBooleanFilters
{
    /**
     * Query string filter values arrive as strings (e.g. "false"), and PHP's `(bool)` cast
     * treats any non-empty string as true — so `(bool) "false"` is `true`. Use this instead.
     */
    private function toBool(mixed $value): bool
    {
        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

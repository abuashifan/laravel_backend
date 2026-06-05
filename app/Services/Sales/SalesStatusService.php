<?php

namespace App\Services\Sales;

class SalesStatusService
{
    public function all(): array
    {
        return (array) config('sales_workflow.statuses', []);
    }

    public function reportable(): array
    {
        return (array) config('sales_workflow.reportable_statuses', []);
    }

    public function hidden(): array
    {
        return (array) config('sales_workflow.hidden_statuses', []);
    }

    public function isHidden(?string $status): bool
    {
        return in_array($status, $this->hidden(), true);
    }

    public function isReportable(?string $status): bool
    {
        return in_array($status, $this->reportable(), true);
    }
}

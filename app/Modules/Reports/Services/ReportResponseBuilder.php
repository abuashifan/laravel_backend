<?php

namespace App\Modules\Reports\Services;

use App\Shared\Reports\Data\ReportMeta;
use App\Shared\Reports\Data\ReportResponse;
use App\Shared\Reports\Data\ReportTotals;

class ReportResponseBuilder
{
    public function build(
        string $reportName,
        array $data,
        array $totals = [],
        array $filters = [],
        array $dimensions = [],
        ?array $fiscalYear = null,
        array $notes = []
    ): array {
        $meta = ReportMeta::make($reportName, $filters, $dimensions, $fiscalYear, $notes);
        $totalsObj = $totals === [] ? null : ReportTotals::make($totals);

        return ReportResponse::make($meta, $data, $totalsObj)->toArray();
    }

    public function meta(
        string $reportName,
        array $filters = [],
        array $dimensions = [],
        ?array $fiscalYear = null,
        array $notes = []
    ): array {
        return ReportMeta::make($reportName, $filters, $dimensions, $fiscalYear, $notes)->toArray();
    }
}

<?php

namespace Tests\Unit\Reports;

use App\Data\Reports\ReportDateRange;
use App\Data\Reports\ReportDimensionFilter;
use App\Models\Tenant\Department;
use App\Models\Tenant\Project;
use App\Services\Reports\ReportFilterService;
use Tests\Feature\Journal\JournalTestCase;

class ReportFilterServiceTest extends JournalTestCase
{
    public function test_normalizes_and_validates_date_range_and_dimensions_and_account_type(): void
    {
        $this->setUpTenant(role: 'owner');

        $svc = new ReportFilterService();

        $range = $svc->normalizeDateRange(['start_date' => '2026-02-01 10:00:00', 'end_date' => '2026-02-28']);
        $this->assertInstanceOf(ReportDateRange::class, $range);
        $this->assertSame('2026-02-01', $range->normalizeToDateString()->startDate);
        $this->assertSame('2026-02-28', $range->normalizeToDateString()->endDate);
        $this->assertTrue($svc->validateDateRange($range)['valid']);

        $invalidRange = new ReportDateRange('2026-03-10', '2026-03-01');
        $invalidRangeResult = $svc->validateDateRange($invalidRange);
        $this->assertFalse($invalidRangeResult['valid']);
        $this->assertArrayHasKey('end_date', $invalidRangeResult['errors']);

        $dept = Department::query()->create(['code' => 'OPS', 'name' => 'Ops', 'is_active' => true]);
        $project = Project::query()->create(['code' => 'PRJ', 'name' => 'Project', 'status' => 'active', 'is_active' => true]);

        $dims = $svc->normalizeDimensions(['department_id' => (string) $dept->id, 'project_id' => (string) $project->id]);
        $this->assertInstanceOf(ReportDimensionFilter::class, $dims);
        $this->assertSame($dept->id, $dims->departmentId);
        $this->assertSame($project->id, $dims->projectId);
        $this->assertTrue($svc->validateDimensions($dims)['valid']);

        $invalidDims = new ReportDimensionFilter(999999, 999999);
        $invalidDimsResult = $svc->validateDimensions($invalidDims);
        $this->assertFalse($invalidDimsResult['valid']);
        $this->assertArrayHasKey('department_id', $invalidDimsResult['errors']);
        $this->assertArrayHasKey('project_id', $invalidDimsResult['errors']);

        $this->assertTrue($svc->validateAccountType('asset')['valid']);
        $this->assertFalse($svc->validateAccountType('invalid')['valid']);
    }
}


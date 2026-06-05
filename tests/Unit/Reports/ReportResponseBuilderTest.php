<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\ReportResponseBuilder;
use Tests\TestCase;

class ReportResponseBuilderTest extends TestCase
{
    public function test_build_returns_meta_data_and_totals(): void
    {
        $builder = new ReportResponseBuilder();

        $result = $builder->build(
            reportName: 'trial_balance',
            data: ['accounts' => [['account_id' => 1]]],
            totals: ['ending_debit' => 100, 'ending_credit' => 100],
            filters: ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            dimensions: ['department_id' => null, 'project_id' => null],
            fiscalYear: ['year' => 2026],
            notes: ['export_ready' => true],
        );

        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('totals', $result);

        $this->assertSame('trial_balance', $result['meta']['report_name']);
        $this->assertNotEmpty($result['meta']['generated_at']);
        $this->assertSame(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $result['meta']['filters']);
        $this->assertSame(['department_id' => null, 'project_id' => null], $result['meta']['dimensions']);
        $this->assertSame(['year' => 2026], $result['meta']['fiscal_year']);
        $this->assertSame(['export_ready' => true], $result['meta']['notes']);

        $this->assertSame(['accounts' => [['account_id' => 1]]], $result['data']);
        $this->assertSame(['ending_debit' => 100, 'ending_credit' => 100], $result['totals']);
    }
}


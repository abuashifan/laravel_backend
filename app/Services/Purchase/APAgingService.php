<?php

namespace App\Services\Purchase;

use Carbon\CarbonImmutable;

class APAgingService
{
    public function __construct(private readonly APSubsidiaryLedgerService $ledgerService)
    {
    }

    public function aging(array $filters = []): array
    {
        $asOf = CarbonImmutable::parse($filters['as_of_date'] ?? now()->toDateString())->startOfDay();
        $rows = collect($this->ledgerService->openBills($filters))
            ->map(function (array $bill) use ($asOf): array {
                $bill['bucket'] = $this->bucketize($bill['due_date'] ?? $bill['bill_date'], $asOf);

                return $bill;
            });

        $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
        foreach ($rows as $row) {
            $buckets[$row['bucket']] = round($buckets[$row['bucket']] + (float) $row['balance_due'], 2);
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'buckets' => $buckets,
            'total' => round(array_sum($buckets), 2),
            'bills' => $rows->values()->all(),
            'vendors' => $rows->groupBy('vendor_id')->map(function ($vendorRows) use ($buckets): array {
                $vendorBuckets = $buckets;
                foreach (array_keys($vendorBuckets) as $bucket) {
                    $vendorBuckets[$bucket] = round((float) $vendorRows->where('bucket', $bucket)->sum('balance_due'), 2);
                }
                $first = $vendorRows->first();

                return [
                    'vendor_id' => $first['vendor_id'],
                    'vendor_name' => $first['vendor_name'],
                    'buckets' => $vendorBuckets,
                    'total' => round((float) $vendorRows->sum('balance_due'), 2),
                ];
            })->values()->all(),
        ];
    }

    public function agingByVendor(int $vendorId, array $filters = []): array
    {
        $filters['vendor_id'] = $vendorId;

        return $this->aging($filters);
    }

    public function bucketize(?string $dueDate, ?CarbonImmutable $asOf = null): string
    {
        $asOf ??= CarbonImmutable::parse(now()->toDateString())->startOfDay();
        $due = CarbonImmutable::parse($dueDate ?? $asOf->toDateString())->startOfDay();
        $daysPastDue = $due->diffInDays($asOf, false);

        if ($daysPastDue <= 0) {
            return 'current';
        }
        if ($daysPastDue <= 30) {
            return '1_30';
        }
        if ($daysPastDue <= 60) {
            return '31_60';
        }
        if ($daysPastDue <= 90) {
            return '61_90';
        }

        return 'over_90';
    }
}

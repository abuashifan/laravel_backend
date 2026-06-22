<?php

namespace App\Services\Sales;

use Carbon\CarbonImmutable;

class ARAgingService
{
    public function __construct(private readonly ARSubsidiaryLedgerService $ledgerService) {}

    public function aging(array $filters = []): array
    {
        $asOf = CarbonImmutable::parse($filters['as_of_date'] ?? now()->toDateString())->startOfDay();
        $rows = collect($this->ledgerService->openInvoices($filters))
            ->map(function (array $invoice) use ($asOf): array {
                $bucket = $this->bucketize($invoice['due_date'] ?? $invoice['invoice_date'], $asOf);
                $invoice['bucket'] = $bucket;

                return $invoice;
            });

        $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
        foreach ($rows as $row) {
            $buckets[$row['bucket']] = round($buckets[$row['bucket']] + (float) $row['balance_due'], 2);
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'buckets' => $buckets,
            'total' => round(array_sum($buckets), 2),
            'invoices' => $rows->values()->all(),
            'customers' => $rows->groupBy('customer_id')->map(function ($customerRows) use ($buckets): array {
                $customerBuckets = $buckets;
                foreach (array_keys($customerBuckets) as $bucket) {
                    $customerBuckets[$bucket] = round((float) $customerRows->where('bucket', $bucket)->sum('balance_due'), 2);
                }
                $first = $customerRows->first();

                return [
                    'customer_id' => $first['customer_id'],
                    'customer_name' => $first['customer_name'],
                    'buckets' => $customerBuckets,
                    'total' => round((float) $customerRows->sum('balance_due'), 2),
                ];
            })->values()->all(),
        ];
    }

    public function agingByCustomer(int $customerId, array $filters = []): array
    {
        $filters['customer_id'] = $customerId;

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

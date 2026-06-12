<?php

namespace App\Services\Accounting;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Services\AccountMapping\AccountMappingService;
use App\Support\AccountMapping\AccountMappingRequirement;

class AccountMappingHealthService
{
    /**
     * @var array<string, string>
     */
    private const CONDITIONAL_WORKFLOWS = [
        'sales.discount' => 'sales invoice discount workflow',
        'sales.return' => 'sales return workflow',
        'sales.tax_output' => 'taxable sales workflow',
        'sales.default_cash_bank' => 'sales receipt default cash/bank workflow',
        'purchase.default_purchase' => 'legacy purchase workflow',
        'purchase.inventory_interim' => 'goods receipt workflow',
        'purchase.tax_input' => 'taxable purchase workflow',
        'purchase.discount' => 'purchase discount workflow',
        'purchase.return' => 'purchase return workflow',
        'purchase.default_cash_bank' => 'vendor payment default cash/bank workflow',
        'inventory.adjustment_gain' => 'positive stock adjustment workflow',
        'inventory.adjustment_loss' => 'negative stock adjustment workflow',
        'inventory.write_off' => 'inventory write-off workflow',
        'cash_bank.bank_admin_fee' => 'bank reconciliation admin fee workflow',
        'cash_bank.bank_interest_income' => 'bank reconciliation interest workflow',
        'journal.suspense' => 'manual journal suspense workflow',
    ];

    public function __construct(private readonly AccountMappingService $definitionService)
    {
    }

    /**
     * @return array{
     *     status:string,
     *     summary:array{total:int,healthy:int,required_missing:int,conditional_missing:int},
     *     required_missing:array<int,array<string,mixed>>,
     *     conditional_missing:array<int,array<string,mixed>>,
     *     healthy:array<int,array<string,mixed>>
     * }
     */
    public function check(): array
    {
        $requirements = $this->definitionService->allRequirements();
        $keys = array_map(fn (AccountMappingRequirement $requirement): string => $requirement->key, $requirements);

        $mappings = AccountMapping::query()
            ->with('account')
            ->whereIn('mapping_key', $keys)
            ->get()
            ->keyBy('mapping_key');

        $requiredMissing = [];
        $conditionalMissing = [];
        $healthy = [];

        foreach ($requirements as $requirement) {
            /** @var AccountMapping|null $mapping */
            $mapping = $mappings->get($requirement->key);
            $account = $mapping?->account;
            $healthIssue = $this->healthIssue($requirement, $mapping, $account);

            if ($healthIssue === null && $mapping?->account_id && $account instanceof ChartOfAccount) {
                $healthy[] = [
                    'key' => $requirement->key,
                    'workflow' => $this->workflowFor($requirement),
                    'account_id' => (int) $account->id,
                    'account_name' => (string) $account->account_name,
                ];

                continue;
            }

            if ($requirement->required && $healthIssue !== null) {
                $requiredMissing[] = [
                    'key' => $requirement->key,
                    'workflow' => $this->workflowFor($requirement),
                    'reason' => $healthIssue,
                ];

                continue;
            }

            if ($this->isConditional($requirement->key) && $mapping !== null && $healthIssue !== null) {
                $conditionalMissing[] = [
                    'key' => $requirement->key,
                    'workflow' => $this->workflowFor($requirement),
                    'reason' => $healthIssue,
                ];
            }
        }

        $status = 'healthy';
        if ($requiredMissing !== []) {
            $status = 'critical';
        } elseif ($conditionalMissing !== []) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'summary' => [
                'total' => count($requirements),
                'healthy' => count($healthy),
                'required_missing' => count($requiredMissing),
                'conditional_missing' => count($conditionalMissing),
            ],
            'required_missing' => $requiredMissing,
            'conditional_missing' => $conditionalMissing,
            'healthy' => $healthy,
        ];
    }

    private function healthIssue(AccountMappingRequirement $requirement, ?AccountMapping $mapping, ?ChartOfAccount $account): ?string
    {
        if (! $mapping instanceof AccountMapping || ! $mapping->is_active) {
            return 'mapping_not_configured';
        }

        if (! $mapping->account_id) {
            return 'account_not_configured';
        }

        if (! $account instanceof ChartOfAccount) {
            return 'account_not_found';
        }

        if (! $account->isActive()) {
            return 'account_inactive';
        }

        if (! $requirement->allowsAccountType((string) $account->account_type)) {
            return 'account_type_invalid';
        }

        return null;
    }

    private function isConditional(string $key): bool
    {
        return array_key_exists($key, self::CONDITIONAL_WORKFLOWS);
    }

    private function workflowFor(AccountMappingRequirement $requirement): string
    {
        return self::CONDITIONAL_WORKFLOWS[$requirement->key] ?? $requirement->module.' core workflow';
    }
}

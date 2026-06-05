<?php

namespace App\Services\Purchase;

class PurchaseAccountMappingService
{
    public function aliases(): array
    {
        return (array) config('purchase_workflow.account_mapping_aliases', []);
    }

    public function keyFor(string $alias): ?string
    {
        return $this->aliases()[$alias] ?? null;
    }

    public function requiredAliases(): array
    {
        return [
            'accounts_payable',
            'purchase_expense',
        ];
    }

    public function optionalAliases(): array
    {
        return array_values(array_diff(array_keys($this->aliases()), $this->requiredAliases()));
    }
}

<?php

namespace App\Services\Sales;

class SalesAccountMappingService
{
    public function aliases(): array
    {
        return (array) config('sales_workflow.account_mapping_aliases', []);
    }

    public function keyFor(string $alias): ?string
    {
        return $this->aliases()[$alias] ?? null;
    }

    public function requiredAliases(): array
    {
        return [
            'accounts_receivable',
            'sales_revenue',
        ];
    }

    public function optionalAliases(): array
    {
        return array_values(array_diff(array_keys($this->aliases()), $this->requiredAliases()));
    }
}

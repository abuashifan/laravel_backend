<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Support\AccountMapping\AccountMappingKey;

class InventoryAccountMappingService
{
    public function getInventoryAccount(): int
    {
        return $this->resolveRequiredAccount(AccountMappingKey::INVENTORY_ASSET);
    }

    public function getInventoryInterimAccount(): ?int
    {
        return $this->resolveOptionalAccount(AccountMappingKey::PURCHASE_INVENTORY_INTERIM);
    }

    public function getCogsAccount(): int
    {
        return $this->resolveRequiredAccount(AccountMappingKey::INVENTORY_COGS);
    }

    public function getStockAdjustmentGainAccount(): ?int
    {
        return $this->resolveOptionalAccount(AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN);
    }

    public function getStockAdjustmentLossAccount(): ?int
    {
        return $this->resolveOptionalAccount(AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS);
    }

    public function getPurchaseReturnAccount(): ?int
    {
        // Reuse purchase return mapping.
        return $this->resolveOptionalAccount(AccountMappingKey::PURCHASE_RETURN);
    }

    public function getSalesReturnAccount(): ?int
    {
        // Reuse sales return mapping.
        return $this->resolveOptionalAccount(AccountMappingKey::SALES_RETURN);
    }

    public function getOpeningStockEquityAccount(): ?int
    {
        // Reuse opening balance equity mapping.
        return $this->resolveOptionalAccount(AccountMappingKey::OPENING_BALANCE_EQUITY);
    }

    public function resolveRequiredAccount(string $key): int
    {
        $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first();
        if (! $mapping?->account_id) {
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', $this->missingMappingMessage($key), 422, [
                'account_mapping' => [$this->missingMappingMessage($key)],
            ]);
        }
        return (int) $mapping->account_id;
    }

    public function resolveOptionalAccount(string $key): ?int
    {
        $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first();
        return $mapping?->account_id ? (int) $mapping->account_id : null;
    }

    public function missingMappingMessage(string $key): string
    {
        $label = match ($key) {
            AccountMappingKey::INVENTORY_ASSET => 'Inventory Asset',
            AccountMappingKey::INVENTORY_COGS => 'Inventory COGS',
            AccountMappingKey::PURCHASE_INVENTORY_INTERIM => 'Purchase Inventory Interim',
            AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN => 'Stock Adjustment Gain',
            AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS => 'Stock Adjustment Loss',
            AccountMappingKey::PURCHASE_RETURN => 'Purchase Return',
            AccountMappingKey::SALES_RETURN => 'Sales Return',
            AccountMappingKey::OPENING_BALANCE_EQUITY => 'Opening Balance Equity',
            default => str($key)->replace(['.', '_'], ' ')->headline()->toString(),
        };

        return $label.' account mapping is not configured. Set it in Account Mappings before posting inventory transactions.';
    }
}

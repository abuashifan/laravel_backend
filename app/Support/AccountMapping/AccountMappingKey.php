<?php

namespace App\Support\AccountMapping;

class AccountMappingKey
{
    // Sales
    public const SALES_ACCOUNTS_RECEIVABLE = 'sales.accounts_receivable';
    public const SALES_REVENUE = 'sales.revenue';
    public const SALES_DISCOUNT = 'sales.discount';
    public const SALES_RETURN = 'sales.return';
    public const SALES_TAX_OUTPUT = 'sales.tax_output';
    public const SALES_CUSTOMER_DEPOSIT = 'sales.customer_deposit';
    public const SALES_DEFAULT_CASH_BANK = 'sales.default_cash_bank';

    // Purchase
    public const PURCHASE_ACCOUNTS_PAYABLE = 'purchase.accounts_payable';
    public const PURCHASE_DEFAULT_PURCHASE = 'purchase.default_purchase';
    public const PURCHASE_EXPENSE = 'purchase.expense';
    public const PURCHASE_INVENTORY_INTERIM = 'purchase.inventory_interim';
    public const PURCHASE_TAX_INPUT = 'purchase.tax_input';
    public const PURCHASE_DISCOUNT = 'purchase.discount';
    public const PURCHASE_RETURN = 'purchase.return';
    public const PURCHASE_VENDOR_DEPOSIT = 'purchase.vendor_deposit';
    public const PURCHASE_DEFAULT_CASH_BANK = 'purchase.default_cash_bank';

    // Inventory
    public const INVENTORY_ASSET = 'inventory.asset';
    public const INVENTORY_COGS = 'inventory.cogs';
    public const INVENTORY_ADJUSTMENT_GAIN = 'inventory.adjustment_gain';
    public const INVENTORY_ADJUSTMENT_LOSS = 'inventory.adjustment_loss';
    public const INVENTORY_WRITE_OFF = 'inventory.write_off';

    // Fixed Assets
    public const FIXED_ASSETS_CLEARING = 'fixed_assets.clearing';
    public const FIXED_ASSETS_COST = 'fixed_assets.cost';
    public const FIXED_ASSETS_ACCUMULATED_DEPRECIATION = 'fixed_assets.accumulated_depreciation';
    public const FIXED_ASSETS_DEPRECIATION_EXPENSE = 'fixed_assets.depreciation_expense';
    public const FIXED_ASSETS_ACCUMULATED_AMORTIZATION = 'fixed_assets.accumulated_amortization';
    public const FIXED_ASSETS_AMORTIZATION_EXPENSE = 'fixed_assets.amortization_expense';
    public const FIXED_ASSETS_DISPOSAL_GAIN = 'fixed_assets.disposal_gain';
    public const FIXED_ASSETS_DISPOSAL_LOSS = 'fixed_assets.disposal_loss';

    // Cash/Bank
    public const CASH_BANK_DEFAULT_CASH = 'cash_bank.default_cash';
    public const CASH_BANK_DEFAULT_BANK = 'cash_bank.default_bank';
    public const CASH_BANK_ADMIN_FEE = 'cash_bank.bank_admin_fee';
    public const CASH_BANK_INTEREST_INCOME = 'cash_bank.bank_interest_income';

    // Opening/Closing
    public const OPENING_BALANCE_EQUITY = 'opening_balance.equity';
    public const CLOSING_RETAINED_EARNINGS = 'closing.retained_earnings';
    public const CLOSING_CURRENT_YEAR_EARNINGS = 'closing.current_year_earnings';

    // Journal
    public const JOURNAL_SUSPENSE = 'journal.suspense';

    public static function all(): array
    {
        return array_keys((array) config('account_mappings.required_mappings', []));
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, (array) config('account_mappings.required_mappings', []));
    }

    public static function moduleFor(string $key): ?string
    {
        $all = (array) config('account_mappings.required_mappings', []);
        $module = $all[$key]['module'] ?? null;

        return is_string($module) ? $module : null;
    }

    public static function requiredKeys(): array
    {
        $required = [];
        foreach ((array) config('account_mappings.required_mappings', []) as $key => $cfg) {
            if ((bool) ($cfg['required'] ?? false)) {
                $required[] = (string) $key;
            }
        }
        return $required;
    }

    public static function optionalKeys(): array
    {
        $optional = [];
        foreach ((array) config('account_mappings.required_mappings', []) as $key => $cfg) {
            if (! (bool) ($cfg['required'] ?? false)) {
                $optional[] = (string) $key;
            }
        }
        return $optional;
    }

    public static function keysForModule(string $module): array
    {
        $keys = [];
        foreach ((array) config('account_mappings.required_mappings', []) as $key => $cfg) {
            if (($cfg['module'] ?? null) === $module) {
                $keys[] = (string) $key;
            }
        }
        return $keys;
    }
}

<?php

namespace App\Support\SourceLink;

class SourceType
{
    public const MANUAL_JOURNAL = 'manual_journal';
    public const OPENING_BALANCE = 'opening_balance';
    public const CLOSING_ENTRY = 'closing_entry';
    public const SALES_QUOTATION = 'sales_quotation';
    public const SALES_ORDER = 'sales_order';
    public const DELIVERY_ORDER = 'delivery_order';
    public const PROFORMA_INVOICE = 'proforma_invoice';
    public const SALES_INVOICE = 'sales_invoice';
    public const SALES_RECEIPT = 'sales_receipt';
    public const SALES_PAYMENT = 'sales_payment';
    public const SALES_RETURN = 'sales_return';
    public const CUSTOMER_DEPOSIT = 'customer_deposit';
    public const PURCHASE_INVOICE = 'purchase_invoice';
    public const PURCHASE_PAYMENT = 'purchase_payment';
    public const PURCHASE_RETURN = 'purchase_return';
    public const CASH_RECEIPT = 'cash_receipt';
    public const CASH_PAYMENT = 'cash_payment';
    public const BANK_TRANSFER = 'bank_transfer';
    public const STOCK_ADJUSTMENT = 'stock_adjustment';
    public const STOCK_MOVEMENT = 'stock_movement';
    public const STOCK_OPNAME = 'stock_opname';
    public const INVENTORY_TRANSFER = 'inventory_transfer';
    public const FIXED_ASSET = 'fixed_asset';
    public const FIXED_ASSET_CAPITALIZATION = 'fixed_asset_capitalization';
    public const FIXED_ASSET_DEPRECIATION = 'fixed_asset_depreciation';
    public const FIXED_ASSET_DISPOSAL = 'fixed_asset_disposal';
    public const PERIOD_END = 'period_end';
    public const IMPORT_BATCH = 'import_batch';
    public const SYSTEM = 'system';

    public static function all(): array
    {
        return (array) config('source_links.source_types', []);
    }

    public static function exists(string $sourceType): bool
    {
        return in_array($sourceType, self::all(), true);
    }
}

<?php

namespace App\Support\DocumentNumbering;

class DocumentType
{
    public const JOURNAL_ENTRY = 'journal_entry';
    public const SALES_QUOTATION = 'sales_quotation';
    public const SALES_ORDER = 'sales_order';
    public const DELIVERY_ORDER = 'delivery_order';
    public const PROFORMA_INVOICE = 'proforma_invoice';
    public const SALES_INVOICE = 'sales_invoice';
    public const SALES_RECEIPT = 'sales_receipt';
    public const SALES_RETURN = 'sales_return';
    public const CUSTOMER_DEPOSIT = 'customer_deposit';
    public const PURCHASE_REQUEST = 'purchase_request';
    public const PURCHASE_ORDER = 'purchase_order';
    public const GOODS_RECEIPT = 'goods_receipt';
    public const VENDOR_BILL = 'vendor_bill';
    public const PURCHASE_INVOICE = 'purchase_invoice';
    public const VENDOR_PAYMENT = 'vendor_payment';
    public const VENDOR_DEPOSIT = 'vendor_deposit';
    public const PURCHASE_RETURN = 'purchase_return';
    public const CASH_RECEIPT = 'cash_receipt';
    public const CASH_PAYMENT = 'cash_payment';
    public const BANK_TRANSFER = 'bank_transfer';
    public const BANK_RECONCILIATION = 'bank_reconciliation';
    public const STOCK_ADJUSTMENT = 'stock_adjustment';
    public const STOCK_MOVEMENT = 'stock_movement';
    public const STOCK_OPNAME = 'stock_opname';
    public const STOCK_TRANSFER = 'stock_transfer';
    public const OPENING_STOCK = 'opening_stock';
    public const OPENING_BALANCE = 'opening_balance';
    public const FIXED_ASSET = 'fixed_asset';
    public const FIXED_ASSET_CAPITALIZATION = 'fixed_asset_capitalization';
    public const FIXED_ASSET_DEPRECIATION = 'fixed_asset_depreciation';
    public const FIXED_ASSET_DISPOSAL = 'fixed_asset_disposal';
    public const CLOSING_ENTRY = 'closing_entry';

    public static function all(): array
    {
        return array_keys((array) config('document_numbers.document_types', []));
    }

    public static function exists(string $documentType): bool
    {
        return array_key_exists($documentType, (array) config('document_numbers.document_types', []));
    }

    public static function defaultPrefix(string $documentType): ?string
    {
        $types = (array) config('document_numbers.document_types', []);
        return $types[$documentType]['prefix'] ?? null;
    }
}

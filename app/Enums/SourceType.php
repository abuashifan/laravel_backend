<?php

namespace App\Enums;

enum SourceType: string
{
    case MANUAL_JOURNAL = 'manual_journal';
    case OPENING_BALANCE = 'opening_balance';
    case CLOSING_ENTRY = 'closing_entry';

    case SALES_QUOTATION = 'sales_quotation';
    case SALES_ORDER = 'sales_order';
    case DELIVERY_ORDER = 'delivery_order';
    case PROFORMA_INVOICE = 'proforma_invoice';
    case SALES_INVOICE = 'sales_invoice';
    case SALES_RECEIPT = 'sales_receipt';
    case SALES_PAYMENT = 'sales_payment';
    case SALES_RETURN = 'sales_return';
    case CUSTOMER_DEPOSIT = 'customer_deposit';
    case CUSTOMER_DEPOSIT_ALLOCATION = 'customer_deposit_allocation';

    case PURCHASE_REQUEST = 'purchase_request';
    case PURCHASE_ORDER = 'purchase_order';
    case PURCHASE_INVOICE = 'purchase_invoice';
    case PURCHASE_PAYMENT = 'purchase_payment';
    case PURCHASE_RETURN = 'purchase_return';
    case GOODS_RECEIPT = 'goods_receipt';
    case VENDOR_BILL = 'vendor_bill';
    case VENDOR_PAYMENT = 'vendor_payment';
    case VENDOR_DEPOSIT = 'vendor_deposit';
    case VENDOR_DEPOSIT_ALLOCATION = 'vendor_deposit_allocation';

    case CASH_RECEIPT = 'cash_receipt';
    case CASH_PAYMENT = 'cash_payment';
    case BANK_TRANSFER = 'bank_transfer';

    case STOCK_ADJUSTMENT = 'stock_adjustment';
    case STOCK_MOVEMENT = 'stock_movement';
    case STOCK_OPNAME = 'stock_opname';
    case INVENTORY_TRANSFER = 'inventory_transfer';
    case REVERSAL = 'reversal';

    case IMPORT_BATCH = 'import_batch';
    case SYSTEM = 'system';
}

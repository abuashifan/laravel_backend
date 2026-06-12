<?php

use App\Http\Controllers\Api\Sales\AccountsReceivableController;
use App\Http\Controllers\Api\Sales\CustomerDepositController;
use App\Http\Controllers\Api\Sales\DeliveryOrderController;
use App\Http\Controllers\Api\Sales\ProformaInvoiceController;
use App\Http\Controllers\Api\Sales\SalesInvoiceController;
use App\Http\Controllers\Api\Sales\SalesOrderController;
use App\Http\Controllers\Api\Sales\SalesQuotationController;
use App\Http\Controllers\Api\Sales\SalesReceiptController;
use App\Http\Controllers\Api\Sales\SalesReturnController;
use App\Http\Controllers\Api\Transactions\SourceDocumentPickerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('sales')->group(function () {
    Route::get('/source-documents/availability', [SourceDocumentPickerController::class, 'availability'])->middleware('permission:sales.orders.view');
    Route::get('/source-documents', [SourceDocumentPickerController::class, 'index'])->middleware('permission:sales.orders.view');

    Route::get('/ar/customer-summary', [AccountsReceivableController::class, 'customerSummary'])->middleware('permission:sales.ar.view');
    Route::get('/ar/customers/{customerId}/ledger', [AccountsReceivableController::class, 'customerLedger'])->middleware('permission:sales.ar.view');
    Route::get('/ar/invoices/{invoiceId}/ledger', [AccountsReceivableController::class, 'invoiceLedger'])->middleware('permission:sales.ar.view');
    Route::get('/ar/open-invoices', [AccountsReceivableController::class, 'openInvoices'])->middleware('permission:sales.ar.view');
    Route::get('/ar/aging', [AccountsReceivableController::class, 'aging'])->middleware('permission:sales.ar.view');
    Route::get('/ar/reconciliation', [AccountsReceivableController::class, 'reconciliation'])->middleware('permission:sales.ar.reconcile');

    Route::get('/quotations', [SalesQuotationController::class, 'index'])->middleware('permission:sales.quotations.view');
    Route::post('/quotations', [SalesQuotationController::class, 'store'])->middleware('permission:sales.quotations.create');
    Route::get('/quotations/{id}', [SalesQuotationController::class, 'show'])->middleware('permission:sales.quotations.view');
    Route::patch('/quotations/{id}', [SalesQuotationController::class, 'update'])->middleware('permission:sales.quotations.edit');
    Route::patch('/quotations/{id}/send', [SalesQuotationController::class, 'send'])->middleware('permission:sales.quotations.edit');
    Route::patch('/quotations/{id}/approve', [SalesQuotationController::class, 'approve'])->middleware('permission:sales.quotations.approve');
    Route::patch('/quotations/{id}/accept', [SalesQuotationController::class, 'accept'])->middleware('permission:sales.quotations.approve');
    Route::patch('/quotations/{id}/reject', [SalesQuotationController::class, 'reject'])->middleware('permission:sales.quotations.cancel');
    Route::patch('/quotations/{id}/cancel', [SalesQuotationController::class, 'cancel'])->middleware('permission:sales.quotations.cancel');

    Route::get('/orders', [SalesOrderController::class, 'index'])->middleware('permission:sales.orders.view');
    Route::post('/orders', [SalesOrderController::class, 'store'])->middleware('permission:sales.orders.create');
    Route::get('/orders/{id}', [SalesOrderController::class, 'show'])->middleware('permission:sales.orders.view');
    Route::patch('/orders/{id}', [SalesOrderController::class, 'update'])->middleware('permission:sales.orders.edit');
    Route::post('/orders/from-quotation/{quotationId}', [SalesOrderController::class, 'createFromQuotation'])->middleware('permission:sales.orders.convert');
    Route::patch('/orders/{id}/approve', [SalesOrderController::class, 'approve'])->middleware('permission:sales.orders.approve');
    Route::patch('/orders/{id}/confirm', [SalesOrderController::class, 'confirm'])->middleware('permission:sales.orders.confirm');
    Route::patch('/orders/{id}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:sales.orders.cancel');
    Route::patch('/orders/{id}/close', [SalesOrderController::class, 'close'])->middleware('permission:sales.orders.confirm');

    Route::get('/delivery-orders', [DeliveryOrderController::class, 'index'])->middleware('permission:sales.delivery_orders.view');
    Route::post('/delivery-orders', [DeliveryOrderController::class, 'store'])->middleware('permission:sales.delivery_orders.create');
    Route::get('/delivery-orders/{id}', [DeliveryOrderController::class, 'show'])->middleware('permission:sales.delivery_orders.view');
    Route::patch('/delivery-orders/{id}', [DeliveryOrderController::class, 'update'])->middleware('permission:sales.delivery_orders.edit');
    Route::post('/delivery-orders/from-sales-order/{salesOrderId}', [DeliveryOrderController::class, 'createFromSalesOrder'])->middleware('permission:sales.delivery_orders.create');
    Route::patch('/delivery-orders/{id}/ready', [DeliveryOrderController::class, 'ready'])->middleware('permission:sales.delivery_orders.ship');
    Route::patch('/delivery-orders/{id}/ship', [DeliveryOrderController::class, 'ship'])->middleware('permission:sales.delivery_orders.ship');
    Route::patch('/delivery-orders/{id}/deliver', [DeliveryOrderController::class, 'deliver'])->middleware('permission:sales.delivery_orders.deliver');
    Route::patch('/delivery-orders/{id}/cancel', [DeliveryOrderController::class, 'cancel'])->middleware('permission:sales.delivery_orders.cancel');
    Route::patch('/delivery-orders/{id}/void', [DeliveryOrderController::class, 'void'])->middleware('permission:sales.delivery_orders.void');

    Route::get('/proformas', [ProformaInvoiceController::class, 'index'])->middleware('permission:sales.proformas.view');
    Route::post('/proformas', [ProformaInvoiceController::class, 'store'])->middleware('permission:sales.proformas.create');
    Route::get('/proformas/{id}', [ProformaInvoiceController::class, 'show'])->middleware('permission:sales.proformas.view');
    Route::patch('/proformas/{id}', [ProformaInvoiceController::class, 'update'])->middleware('permission:sales.proformas.edit');
    Route::post('/proformas/from-sales-order/{salesOrderId}', [ProformaInvoiceController::class, 'createFromSalesOrder'])->middleware('permission:sales.proformas.convert');
    Route::patch('/proformas/{id}/issue', [ProformaInvoiceController::class, 'issue'])->middleware('permission:sales.proformas.issue');
    Route::patch('/proformas/{id}/accept', [ProformaInvoiceController::class, 'accept'])->middleware('permission:sales.proformas.issue');
    Route::patch('/proformas/{id}/cancel', [ProformaInvoiceController::class, 'cancel'])->middleware('permission:sales.proformas.cancel');

    Route::get('/invoices', [SalesInvoiceController::class, 'index'])->middleware('permission:sales.invoices.view');
    Route::post('/invoices', [SalesInvoiceController::class, 'store'])->middleware('permission:sales.invoices.create');
    Route::get('/invoices/{id}', [SalesInvoiceController::class, 'show'])->middleware('permission:sales.invoices.view');
    Route::patch('/invoices/{id}', [SalesInvoiceController::class, 'update'])->middleware('permission:sales.invoices.edit');
    Route::post('/invoices/from-sales-order/{salesOrderId}', [SalesInvoiceController::class, 'createFromSalesOrder'])->middleware('permission:sales.invoices.create');
    Route::post('/invoices/from-delivery-order/{deliveryOrderId}', [SalesInvoiceController::class, 'createFromDeliveryOrder'])->middleware('permission:sales.invoices.create');
    Route::post('/invoices/from-proforma/{proformaId}', [SalesInvoiceController::class, 'createFromProforma'])->middleware('permission:sales.invoices.create');
    Route::patch('/invoices/{id}/approve', [SalesInvoiceController::class, 'approve'])->middleware('permission:sales.invoices.approve');
    Route::patch('/invoices/{id}/post', [SalesInvoiceController::class, 'post'])->middleware('permission:sales.invoices.post');
    Route::patch('/invoices/{id}/void', [SalesInvoiceController::class, 'void'])->middleware('permission:sales.invoices.void');

    Route::get('/customer-deposits', [CustomerDepositController::class, 'index'])->middleware('permission:sales.deposits.view');
    Route::post('/customer-deposits', [CustomerDepositController::class, 'store'])->middleware('permission:sales.deposits.create');
    Route::get('/customer-deposits/available', [CustomerDepositController::class, 'available'])->middleware('permission:sales.deposits.view|sales.receipts.view');
    Route::get('/customer-deposits/{id}', [CustomerDepositController::class, 'show'])->middleware('permission:sales.deposits.view');
    Route::patch('/customer-deposits/{id}/post', [CustomerDepositController::class, 'post'])->middleware('permission:sales.deposits.post');
    Route::patch('/customer-deposits/{id}/void', [CustomerDepositController::class, 'void'])->middleware('permission:sales.deposits.void');
    Route::patch('/customer-deposits/{id}/refund', [CustomerDepositController::class, 'refund'])->middleware('permission:sales.deposits.refund');
    Route::post('/customer-deposits/{id}/allocate-to-invoice/{invoiceId}', [CustomerDepositController::class, 'allocateToInvoice'])->middleware('permission:sales.deposits.post');

    Route::get('/receipts', [SalesReceiptController::class, 'index'])->middleware('permission:sales.receipts.view');
    Route::post('/receipts', [SalesReceiptController::class, 'store'])->middleware('permission:sales.receipts.create');
    Route::get('/receipts/customer-context', [SalesReceiptController::class, 'customerContext'])->middleware('permission:sales.receipts.view');
    Route::get('/receipts/{id}', [SalesReceiptController::class, 'show'])->middleware('permission:sales.receipts.view');
    Route::patch('/receipts/{id}/post', [SalesReceiptController::class, 'post'])->middleware('permission:sales.receipts.post');
    Route::patch('/receipts/{id}/void', [SalesReceiptController::class, 'void'])->middleware('permission:sales.receipts.void');

    Route::get('/returns', [SalesReturnController::class, 'index'])->middleware('permission:sales.returns.view');
    Route::post('/returns', [SalesReturnController::class, 'store'])->middleware('permission:sales.returns.create');
    Route::get('/returns/{id}', [SalesReturnController::class, 'show'])->middleware('permission:sales.returns.view');
    Route::patch('/returns/{id}', [SalesReturnController::class, 'update'])->middleware('permission:sales.returns.create');
    Route::post('/returns/from-invoice/{invoiceId}', [SalesReturnController::class, 'createFromSalesInvoice'])->middleware('permission:sales.returns.create');
    Route::post('/returns/from-delivery-order/{deliveryOrderId}', [SalesReturnController::class, 'createFromDeliveryOrder'])->middleware('permission:sales.returns.create');
    Route::patch('/returns/{id}/approve', [SalesReturnController::class, 'approve'])->middleware('permission:sales.returns.approve');
    Route::patch('/returns/{id}/post', [SalesReturnController::class, 'post'])->middleware('permission:sales.returns.post');
    Route::patch('/returns/{id}/void', [SalesReturnController::class, 'void'])->middleware('permission:sales.returns.void');
});

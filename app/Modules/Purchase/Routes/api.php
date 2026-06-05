<?php

use App\Http\Controllers\Api\Purchase\AccountsPayableController;
use App\Http\Controllers\Api\Purchase\GoodsReceiptController;
use App\Http\Controllers\Api\Purchase\PurchaseOrderController;
use App\Http\Controllers\Api\Purchase\PurchaseRequestController;
use App\Http\Controllers\Api\Purchase\PurchaseReturnController;
use App\Http\Controllers\Api\Purchase\VendorBillController;
use App\Http\Controllers\Api\Purchase\VendorDepositController;
use App\Http\Controllers\Api\Purchase\VendorPaymentController;
use App\Http\Controllers\Api\Transactions\SourceDocumentPickerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('purchase')->group(function () {
    Route::get('/source-documents/availability', [SourceDocumentPickerController::class, 'availability'])->middleware('permission:purchase.orders.view');
    Route::get('/source-documents', [SourceDocumentPickerController::class, 'index'])->middleware('permission:purchase.orders.view');

    Route::get('/ap/vendor-summary', [AccountsPayableController::class, 'vendorSummary'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/vendors/{vendorId}/ledger', [AccountsPayableController::class, 'vendorLedger'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/bills/{billId}/ledger', [AccountsPayableController::class, 'billLedger'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/open-bills', [AccountsPayableController::class, 'openBills'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/aging', [AccountsPayableController::class, 'aging'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/reconciliation', [AccountsPayableController::class, 'reconciliation'])->middleware('permission:purchase.ap.reconcile');

    Route::get('/requests', [PurchaseRequestController::class, 'index'])->middleware('permission:purchase.requests.view');
    Route::post('/requests', [PurchaseRequestController::class, 'store'])->middleware('permission:purchase.requests.create');
    Route::get('/requests/{id}', [PurchaseRequestController::class, 'show'])->middleware('permission:purchase.requests.view');
    Route::patch('/requests/{id}', [PurchaseRequestController::class, 'update'])->middleware('permission:purchase.requests.edit');
    Route::patch('/requests/{id}/submit', [PurchaseRequestController::class, 'submit'])->middleware('permission:purchase.requests.edit');
    Route::patch('/requests/{id}/approve', [PurchaseRequestController::class, 'approve'])->middleware('permission:purchase.requests.approve');
    Route::patch('/requests/{id}/reject', [PurchaseRequestController::class, 'reject'])->middleware('permission:purchase.requests.cancel');
    Route::patch('/requests/{id}/cancel', [PurchaseRequestController::class, 'cancel'])->middleware('permission:purchase.requests.cancel');

    Route::get('/orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase.orders.view');
    Route::post('/orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase.orders.create');
    Route::get('/orders/{id}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase.orders.view');
    Route::patch('/orders/{id}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchase.orders.edit');
    Route::post('/orders/from-request/{purchaseRequestId}', [PurchaseOrderController::class, 'createFromPurchaseRequest'])->middleware('permission:purchase.orders.convert');
    Route::patch('/orders/{id}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchase.orders.approve');
    Route::patch('/orders/{id}/confirm', [PurchaseOrderController::class, 'confirm'])->middleware('permission:purchase.orders.confirm');
    Route::patch('/orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase.orders.cancel');
    Route::patch('/orders/{id}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:purchase.orders.confirm');

    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:purchase.goods_receipts.view');
    Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:purchase.goods_receipts.create');
    Route::get('/goods-receipts/{id}', [GoodsReceiptController::class, 'show'])->middleware('permission:purchase.goods_receipts.view');
    Route::patch('/goods-receipts/{id}', [GoodsReceiptController::class, 'update'])->middleware('permission:purchase.goods_receipts.edit');
    Route::post('/goods-receipts/from-purchase-order/{purchaseOrderId}', [GoodsReceiptController::class, 'createFromPurchaseOrder'])->middleware('permission:purchase.goods_receipts.create');
    Route::patch('/goods-receipts/{id}/receive', [GoodsReceiptController::class, 'receive'])->middleware('permission:purchase.goods_receipts.receive');
    Route::patch('/goods-receipts/{id}/cancel', [GoodsReceiptController::class, 'cancel'])->middleware('permission:purchase.goods_receipts.cancel');
    Route::patch('/goods-receipts/{id}/void', [GoodsReceiptController::class, 'void'])->middleware('permission:purchase.goods_receipts.void');

    Route::get('/bills', [VendorBillController::class, 'index'])->middleware('permission:purchase.bills.view');
    Route::post('/bills', [VendorBillController::class, 'store'])->middleware('permission:purchase.bills.create');
    Route::get('/bills/{id}', [VendorBillController::class, 'show'])->middleware('permission:purchase.bills.view');
    Route::patch('/bills/{id}', [VendorBillController::class, 'update'])->middleware('permission:purchase.bills.edit');
    Route::post('/bills/from-purchase-order/{purchaseOrderId}', [VendorBillController::class, 'createFromPurchaseOrder'])->middleware('permission:purchase.bills.create');
    Route::post('/bills/from-goods-receipt/{goodsReceiptId}', [VendorBillController::class, 'createFromGoodsReceipt'])->middleware('permission:purchase.bills.create');
    Route::patch('/bills/{id}/approve', [VendorBillController::class, 'approve'])->middleware('permission:purchase.bills.approve');
    Route::patch('/bills/{id}/post', [VendorBillController::class, 'post'])->middleware('permission:purchase.bills.post');
    Route::patch('/bills/{id}/void', [VendorBillController::class, 'void'])->middleware('permission:purchase.bills.void');

    Route::get('/vendor-deposits', [VendorDepositController::class, 'index'])->middleware('permission:purchase.deposits.view');
    Route::post('/vendor-deposits', [VendorDepositController::class, 'store'])->middleware('permission:purchase.deposits.create');
    Route::get('/vendor-deposits/{id}', [VendorDepositController::class, 'show'])->middleware('permission:purchase.deposits.view');
    Route::patch('/vendor-deposits/{id}/post', [VendorDepositController::class, 'post'])->middleware('permission:purchase.deposits.post');
    Route::patch('/vendor-deposits/{id}/void', [VendorDepositController::class, 'void'])->middleware('permission:purchase.deposits.void');
    Route::patch('/vendor-deposits/{id}/refund', [VendorDepositController::class, 'refund'])->middleware('permission:purchase.deposits.refund');
    Route::post('/vendor-deposits/{id}/allocate-to-bill/{billId}', [VendorDepositController::class, 'allocateToBill'])->middleware('permission:purchase.deposits.post');

    Route::get('/payments', [VendorPaymentController::class, 'index'])->middleware('permission:purchase.payments.view');
    Route::post('/payments', [VendorPaymentController::class, 'store'])->middleware('permission:purchase.payments.create');
    Route::get('/payments/{id}', [VendorPaymentController::class, 'show'])->middleware('permission:purchase.payments.view');
    Route::patch('/payments/{id}/post', [VendorPaymentController::class, 'post'])->middleware('permission:purchase.payments.post');
    Route::patch('/payments/{id}/void', [VendorPaymentController::class, 'void'])->middleware('permission:purchase.payments.void');

    Route::get('/returns', [PurchaseReturnController::class, 'index'])->middleware('permission:purchase.returns.view');
    Route::post('/returns', [PurchaseReturnController::class, 'store'])->middleware('permission:purchase.returns.create');
    Route::get('/returns/{id}', [PurchaseReturnController::class, 'show'])->middleware('permission:purchase.returns.view');
    Route::patch('/returns/{id}', [PurchaseReturnController::class, 'update'])->middleware('permission:purchase.returns.create');
    Route::post('/returns/from-bill/{billId}', [PurchaseReturnController::class, 'createFromVendorBill'])->middleware('permission:purchase.returns.create');
    Route::post('/returns/from-goods-receipt/{goodsReceiptId}', [PurchaseReturnController::class, 'createFromGoodsReceipt'])->middleware('permission:purchase.returns.create');
    Route::patch('/returns/{id}/approve', [PurchaseReturnController::class, 'approve'])->middleware('permission:purchase.returns.approve');
    Route::patch('/returns/{id}/post', [PurchaseReturnController::class, 'post'])->middleware('permission:purchase.returns.post');
    Route::patch('/returns/{id}/void', [PurchaseReturnController::class, 'void'])->middleware('permission:purchase.returns.void');
});

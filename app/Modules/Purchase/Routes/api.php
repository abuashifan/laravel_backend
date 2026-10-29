<?php

use App\Modules\Purchase\Controllers\AccountsPayableController;
use App\Modules\Purchase\Controllers\GoodsReceiptController;
use App\Modules\Purchase\Controllers\PurchaseOrderController;
use App\Modules\Purchase\Controllers\PurchaseRequestController;
use App\Modules\Purchase\Controllers\PurchaseReturnController;
use App\Modules\Purchase\Controllers\VendorBillController;
use App\Modules\Purchase\Controllers\VendorDepositController;
use App\Modules\Purchase\Controllers\VendorPaymentController;
use App\Shared\SourceDocument\SourceDocumentPickerController;
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
    // Harus sebelum `/requests/{id}` supaya "adjacent" tidak tertangkap sebagai id.
    Route::get('/requests/adjacent', [PurchaseRequestController::class, 'adjacent'])->middleware('permission:purchase.requests.view');
    Route::post('/requests', [PurchaseRequestController::class, 'store'])->middleware('permission:purchase.requests.create');
    Route::get('/requests/{id}', [PurchaseRequestController::class, 'show'])->middleware('permission:purchase.requests.view');
    Route::patch('/requests/{id}', [PurchaseRequestController::class, 'update'])->middleware('permission:purchase.requests.edit');
    Route::patch('/requests/{id}/submit', [PurchaseRequestController::class, 'submit'])->middleware('permission:purchase.requests.edit');
    Route::patch('/requests/{id}/approve', [PurchaseRequestController::class, 'approve'])->middleware('permission:purchase.requests.approve');
    Route::patch('/requests/{id}/reject', [PurchaseRequestController::class, 'reject'])->middleware('permission:purchase.requests.delete');
    Route::patch('/requests/{id}/cancel', [PurchaseRequestController::class, 'cancel'])->middleware('permission:purchase.requests.delete');

    Route::get('/orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase.orders.view');
    Route::get('/orders/adjacent', [PurchaseOrderController::class, 'adjacent'])->middleware('permission:purchase.orders.view');
    Route::post('/orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase.orders.create');
    Route::get('/orders/{id}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase.orders.view');
    Route::patch('/orders/{id}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchase.orders.edit');
    Route::post('/orders/from-request/{purchaseRequestId}', [PurchaseOrderController::class, 'createFromPurchaseRequest'])->middleware('permission:purchase.documents.convert');
    Route::patch('/orders/{id}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchase.orders.approve');
    Route::patch('/orders/{id}/confirm', [PurchaseOrderController::class, 'confirm'])->middleware('permission:purchase.orders.approve');
    Route::patch('/orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase.orders.delete');
    Route::patch('/orders/{id}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:purchase.orders.approve');

    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:purchase.goods_receipts.view');
    Route::get('/goods-receipts/adjacent', [GoodsReceiptController::class, 'adjacent'])->middleware('permission:purchase.goods_receipts.view');
    Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:purchase.goods_receipts.create');
    Route::get('/goods-receipts/{id}', [GoodsReceiptController::class, 'show'])->middleware('permission:purchase.goods_receipts.view');
    Route::patch('/goods-receipts/{id}', [GoodsReceiptController::class, 'update'])->middleware('permission:purchase.goods_receipts.edit');
    Route::post('/goods-receipts/from-purchase-order/{purchaseOrderId}', [GoodsReceiptController::class, 'createFromPurchaseOrder'])->middleware('permission:purchase.goods_receipts.create');
    Route::patch('/goods-receipts/{id}/receive', [GoodsReceiptController::class, 'receive'])->middleware('permission:purchase.goods_receipts.approve');
    Route::patch('/goods-receipts/{id}/cancel', [GoodsReceiptController::class, 'cancel'])->middleware('permission:purchase.goods_receipts.delete');
    Route::patch('/goods-receipts/{id}/void', [GoodsReceiptController::class, 'void'])->middleware('permission:purchase.goods_receipts.delete');
    Route::delete('/goods-receipts/{id}', [GoodsReceiptController::class, 'destroy'])->middleware('permission:purchase.goods_receipts.delete');

    Route::get('/bills', [VendorBillController::class, 'index'])->middleware('permission:purchase.bills.view');
    Route::get('/bills/adjacent', [VendorBillController::class, 'adjacent'])->middleware('permission:purchase.bills.view');
    Route::post('/bills', [VendorBillController::class, 'store'])->middleware('permission:purchase.bills.create');
    Route::get('/bills/{id}', [VendorBillController::class, 'show'])->middleware('permission:purchase.bills.view');
    Route::patch('/bills/{id}', [VendorBillController::class, 'update'])->middleware('permission:purchase.bills.edit');
    Route::post('/bills/from-purchase-order/{purchaseOrderId}', [VendorBillController::class, 'createFromPurchaseOrder'])->middleware('permission:purchase.bills.create');
    Route::post('/bills/from-goods-receipt/{goodsReceiptId}', [VendorBillController::class, 'createFromGoodsReceipt'])->middleware('permission:purchase.bills.create');
    Route::patch('/bills/{id}/approve', [VendorBillController::class, 'approve'])->middleware('permission:purchase.bills.approve');
    Route::patch('/bills/{id}/post', [VendorBillController::class, 'post'])->middleware('permission:purchase.bills.approve');
    Route::patch('/bills/{id}/void', [VendorBillController::class, 'void'])->middleware('permission:purchase.bills.delete');
    // Hapus mengikuti mode lock/open; draft = izin edit, sudah berefek = dibatalkan seperti void.
    Route::delete('/bills/{id}', [VendorBillController::class, 'destroy'])->middleware('permission:purchase.bills.delete');

    Route::get('/vendor-deposits', [VendorDepositController::class, 'index'])->middleware('permission:purchase.deposits.view');
    Route::get('/vendor-deposits/adjacent', [VendorDepositController::class, 'adjacent'])->middleware('permission:purchase.deposits.view');
    Route::post('/vendor-deposits', [VendorDepositController::class, 'store'])->middleware('permission:purchase.deposits.create');
    Route::get('/vendor-deposits/available', [VendorDepositController::class, 'available'])->middleware('permission:purchase.deposits.view|purchase.payments.view');
    Route::get('/vendor-deposits/{id}', [VendorDepositController::class, 'show'])->middleware('permission:purchase.deposits.view');
    Route::patch('/vendor-deposits/{id}/post', [VendorDepositController::class, 'post'])->middleware('permission:purchase.deposits.approve');
    Route::patch('/vendor-deposits/{id}/void', [VendorDepositController::class, 'void'])->middleware('permission:purchase.deposits.delete');
    Route::patch('/vendor-deposits/{id}', [VendorDepositController::class, 'update'])->middleware('permission:purchase.deposits.create');
    Route::delete('/vendor-deposits/{id}', [VendorDepositController::class, 'destroy'])->middleware('permission:purchase.deposits.delete');
    Route::patch('/vendor-deposits/{id}/refund', [VendorDepositController::class, 'refund'])->middleware('permission:purchase.deposits.refund');
    Route::post('/vendor-deposits/{id}/allocate-to-bill/{billId}', [VendorDepositController::class, 'allocateToBill'])->middleware('permission:purchase.deposits.approve');

    Route::get('/payments', [VendorPaymentController::class, 'index'])->middleware('permission:purchase.payments.view');
    Route::get('/payments/adjacent', [VendorPaymentController::class, 'adjacent'])->middleware('permission:purchase.payments.view');
    Route::post('/payments', [VendorPaymentController::class, 'store'])->middleware('permission:purchase.payments.create');
    Route::get('/payments/vendor-context', [VendorPaymentController::class, 'vendorContext'])->middleware('permission:purchase.payments.view');
    Route::get('/payments/{id}', [VendorPaymentController::class, 'show'])->middleware('permission:purchase.payments.view');
    Route::patch('/payments/{id}/post', [VendorPaymentController::class, 'post'])->middleware('permission:purchase.payments.approve');
    Route::patch('/payments/{id}/void', [VendorPaymentController::class, 'void'])->middleware('permission:purchase.payments.delete');
    Route::patch('/payments/{id}', [VendorPaymentController::class, 'update'])->middleware('permission:purchase.payments.create');
    Route::delete('/payments/{id}', [VendorPaymentController::class, 'destroy'])->middleware('permission:purchase.payments.delete');

    Route::get('/returns', [PurchaseReturnController::class, 'index'])->middleware('permission:purchase.returns.view');
    Route::get('/returns/adjacent', [PurchaseReturnController::class, 'adjacent'])->middleware('permission:purchase.returns.view');
    Route::post('/returns', [PurchaseReturnController::class, 'store'])->middleware('permission:purchase.returns.create');
    Route::get('/returns/{id}', [PurchaseReturnController::class, 'show'])->middleware('permission:purchase.returns.view');
    Route::patch('/returns/{id}', [PurchaseReturnController::class, 'update'])->middleware('permission:purchase.returns.create');
    Route::post('/returns/from-bill/{billId}', [PurchaseReturnController::class, 'createFromVendorBill'])->middleware('permission:purchase.returns.create');
    Route::post('/returns/from-goods-receipt/{goodsReceiptId}', [PurchaseReturnController::class, 'createFromGoodsReceipt'])->middleware('permission:purchase.returns.create');
    Route::patch('/returns/{id}/approve', [PurchaseReturnController::class, 'approve'])->middleware('permission:purchase.returns.approve');
    Route::patch('/returns/{id}/post', [PurchaseReturnController::class, 'post'])->middleware('permission:purchase.returns.approve');
    Route::patch('/returns/{id}/void', [PurchaseReturnController::class, 'void'])->middleware('permission:purchase.returns.delete');
    Route::delete('/returns/{id}', [PurchaseReturnController::class, 'destroy'])->middleware('permission:purchase.returns.delete');
});
